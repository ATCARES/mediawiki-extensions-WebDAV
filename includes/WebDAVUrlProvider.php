<?php

class WebDAVUrlProvider {

	/**
	 *
	 * @var \WebRequest
	 */
	protected $oRequest;

	/**
	 *
	 * @var string
	 */
	protected $sServer;

	/**
	 *
	 * @var string
	 */
	protected $sWebDAVUrlBaseUri;

	/**
	 *
	 * @var int
	 */
	protected $iWebDAVAuthType;

	/**
	 *
	 * @var \User
	 */
	protected $oUser;

	/**
	 *
	 * @param string $server
	 * @param string $webDAVUrlBaseUri
	 * @param int $webDAVAuthType
	 * @param \WebRequest $request
	 * @param \User $user
	 */
	public function __construct( $server, $webDAVUrlBaseUri, $webDAVAuthType, $request, $user ) {
		$this->sServer = $server;
		$this->sWebDAVUrlBaseUri = $webDAVUrlBaseUri;
		$this->iWebDAVAuthType = $webDAVAuthType;
		$this->oRequest = $request;
		$this->oUser = $user;
	}

	/**
	 *
	 * @param Title $title
	 * @return string
	 */
	public function getURL( Title $title ) {
		$path = MWNamespace::getCanonicalName( NS_MEDIA );
		$filename = $title->getText();
		$filename = str_replace( ' ', '_', $filename );

		if ( $this->iWebDAVAuthType === WEBDAV_AUTH_TOKEN ) {
			$sToken = $this->getToken( $title->getText() );
			$path = $sToken . $path;
		}
		\Hooks::run( 'WebDAVUrlProviderGetUrl', [ &$path, &$filename, $title ] );

		$sUrl = $this->sServer . $this->sWebDAVUrlBaseUri . $path . '/' . $filename;

		return $sUrl;
	}

	/**
	 *
	 * @param string $filename
	 * @return string
	 */
	protected function getToken( $filename ) {
		$webDAVTokenizer = \MediaWiki\MediaWikiServices::getInstance()->getService( 'WebDAVTokenizer' );
		$webDAVTokenizer->setUser( $this->oUser );
		return 'tkn' . $webDAVTokenizer->getTokenForFile( $filename ) . '/';
	}
}
