<?php
/**
 * provides search functionalty
 */
abstract class OC_Migration_Provider{

	protected $id=false;
	protected $content=false;
	protected $uid=false;
	protected $olduid=false;
	protected $appinfo=false;

	public function __construct( $appid ) {
		// Set the id
		$this->id = $appid;
		OC_Migrate::registerProvider( $this );
	}

	/**
	 * exports data for apps
	 * @return array appdata to be exported
	 */
	abstract function export( );

	/**
	 * imports data for the app
	 * @return void
	 */
	abstract function import( );

	/**
	* sets the OC_Migration_Content object to $this->content
	* @param OC_Migration_Content $content a OC_Migration_Content object
	*/
	public function setData( $uid, $content, $info=null ) {
		$this->content = $content;
		$this->uid = $uid;
		$id = $this->id;
		if( !is_null( $info ) ) {
			$this->olduid = $info->exporteduser;
			$this->appinfo = $info->apps->$id;
		}
	}

	/**
	* returns the appid of the provider
	* @return string
	*/
	public function getID() {
		return $this->id;
	}
}
