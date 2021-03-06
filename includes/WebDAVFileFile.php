<?php

class WebDAVFileFile extends Sabre\DAV\File {
	/**
	 *
	 * @var File
	 */
	protected $oFile = null;

	/**
	 *
	 * @var Title
	 */
	protected $oTitle = null;

	/**
	 *
	 * @var WikiPage
	 */
	protected $oWikiPage = null;

	/**
	 *
	 * @param File $file
	 */
	public function __construct( $file ) {
		$this->oFile = $file;
		$this->oTitle = $this->oFile->getTitle();
		$this->oWikiPage = WikiPage::factory( $this->getTitle() );
	}

	/**
	 *
	 * @return Title
	 */
	public function getTitle() {
		return $this->oTitle;
	}

	/**
	 *
	 * @return WikiPage
	 */
	public function getWikiPage() {
		return $this->oWikiPage;
	}

	/**
	 *
	 * @return string
	 */
	public function getName() {
		return $this->oFile->getName();
	}

	/**
	 *
	 * @return int
	 */
	public function getSize() {
		return $this->oFile->getSize();
	}

	/**
	 * Unix timestamp
	 * @return int
	 */
	public function getLastModified() {
		return wfTimestamp( TS_UNIX, $this->oFile->getTimestamp() );
	}

	/**
	 *
	 * @return string
	 */
	public function getContentType() {
		return $this->oFile->getMimeType();
	}

	/**
	 *
	 * @return File
	 */
	public function getFileObj() {
		return $this->oFile;
	}

	/**
	 *
	 * @return resource
	 */
	public function get() {
		$be = $this->oFile->getRepo()->getBackend();
		$localFile = $be->getLocalReference(
			[ 'src' => $this->oFile->getPath() ]
		);

		return fopen( $localFile->getPath(), 'r' );
	}

	/**
	 *
	 * @param resource $data
	 * @return void
	 */
	public function put( $data ) {
		wfDebugLog( 'WebDAV', __CLASS__ . ': Receiving data for ' . $this->oFile->getName() );
		$tmpPath = self::makeTmpFileName( $this->oFile->getName() );
		$data = stream_get_contents( $data );
		$fp = fopen( $tmpPath, 'wb' );
		fwrite( $fp, $data );
		fclose( $fp );

		if ( !\Hooks::run( 'WebDAVFileFilePutBeforePublish', [ $tmpPath, $this->oFile ] ) ) {
			return;
		}

		self::publishToWiki( $tmpPath, $this->oFile->getName() );
	}

	/**
	 * This is similar to MWPageFile implementation. Common base class?
	 * @param string $name
	 * @throws Sabre\DAV\Exception\Forbidden
	 */
	public function setName( $name ) {
		$targetTitle = Title::makeTitle( NS_FILE, $name );
		$result = $this->getTitle()->moveTo( $targetTitle );
		if ( !$result === true ) {
			wfDebugLog(
				'WebDAV',
				__CLASS__ . ': Error when trying to change name of "' . $this->getTitle()->getPrefixedText()
					. '" to "' . $targetTitle->getPrefixedText() . '": ' . var_export( $result, true )
			);
			throw new Sabre\DAV\Exception\Forbidden( 'Permission denied to rename file' );
		}
		wfDebugLog(
			'WebDAV',
			__CLASS__ . ': Changed name of "' . $this->getTitle()->getPrefixedText()
				. '" to "' . $targetTitle->getPrefixedText() . '"'
		);
	}

	public function delete() {
		$result = $this->oFile->delete(
			wfMessage( 'webdav-default-delete-comment' )->plain()
		);
		if ( !$result === true ) {
			wfDebugLog(
				'WebDAV',
				__CLASS__ . ': Error when trying to delete "' . $this->getTitle()->getPrefixedText() . '"'
			);
			throw new Sabre\DAV\Exception\Forbidden( 'Permission denied to delete file' );
		}
		wfDebugLog(
			'WebDAV',
			__CLASS__ . ': Deleted "' . $this->getTitle()->getPrefixedText() . '"'
		);
	}

	/**
	 * Adapted from BsFileSystemHelper::uploadLocalFile
	 *
	 * @global FileRepo $wgLocalFileRepo
	 * @param string $sourceFilePath
	 * @param string $targetFileName
	 */
	public static function publishToWiki( $sourceFilePath, $targetFileName ) {
		global $wgLocalFileRepo;
		# Validate a title
		// This title object is no longer necessary, other than to verify
		// that file target name is valid
		$title = Title::makeTitleSafe( NS_FILE, $targetFileName );
		if ( !is_object( $title ) ) {
			$msg = "{$targetFileName} could not be imported; a valid Title cannot be produced";
			wfDebugLog( 'WebDAV', __CLASS__ . ": $msg" );
			throw new Sabre\DAV\Exception\Forbidden( $msg );
		}

		$user = RequestContext::getMain()->getUser();
		// reload from DB!
		$user->clearInstanceCache( 'name' );
		if ( $user->isBlocked() ) {
			$msg = $user->getName() . " was blocked! Aborting.";
			wfDebugLog( 'WebDAV', __CLASS__ . ": $msg" );
			throw new Sabre\DAV\Exception\Forbidden( $msg );
		}

		$uploadStash = new UploadStash( new LocalRepo( $wgLocalFileRepo ), $user );
		$uploadFile = $uploadStash->stashFile( $sourceFilePath, "file" );

		if ( $uploadFile === false ) {
			$msg = "Could not stash file {$targetFileName}";
			wfDebugLog( 'WebDAV', __CLASS__ . ": $msg" );
			throw new Sabre\DAV\Exception\Forbidden( $msg );
		}

		$uploadFromStash = new UploadFromStash( $user, $uploadStash, $wgLocalFileRepo );
		$uploadFromStash->initialize( $uploadFile->getFileKey(), $targetFileName );
		$verifyStatus = $uploadFromStash->verifyUpload();

		if ( $verifyStatus['status'] != UploadBase::OK ) {
			$msg = "File for upload could not be verified {$targetFileName}";
			wfDebugLog( 'WebDAV', __CLASS__ . ": $msg" );
			throw new Sabre\DAV\Exception\Forbidden( $msg );
		}

		$commentText = wfMessage( 'webdav-default-edit-comment' )->plain();

		$uploadStatus = $uploadFromStash->performUpload( $commentText, '', false, $user );
		$uploadFromStash->cleanupTempFile();

		if ( !$uploadStatus->isGood() ) {
			$msg = "Could not upload {$targetFileName}. (" . $uploadStatus->getWikiText() . ")";
			wfDebugLog( 'WebDAV', __CLASS__ . ": $msg" );
			throw new Sabre\DAV\Exception\Forbidden( $msg );
		}

		$repoFile = wfFindFile( $targetFileName );
		if ( $repoFile !== false ) {
			$repoFileTitle = $repoFile->getTitle();
			$page = WikiPage::factory( $repoFileTitle );
			$page->doEditContent( new WikitextContent( '' ), '' );

			$searchUpdate = new SearchUpdate( $repoFileTitle->getArticleID(), $repoFileTitle, '' );
			$searchUpdate->doUpdate();
		}
	}

	/**
	 * In combination with other extensions like 'NSFileRepoConnector' there
	 * might be invalid chars in the name (e.g. ':')
	 * @param string $name the filename on the wiki
	 * @return string a tmp filename for file system storage
	 */
	public static function makeTmpFileName( $name ) {
		$config = \MediaWiki\MediaWikiServices::getInstance()
			->getConfigFactory()->makeConfig( 'wg' );

		$name = preg_replace(
			$config->get( 'WebDAVInvalidFileNameCharsRegEx' ),
			'',
			$name
		);

		$tempDir = wfTempDir() . '/WebDAV';
		wfMkdirParents( $tempDir );

		return $tempDir . '/' . $name;
	}
}
