<?php if (!defined('APPLICATION')) exit();
class Gdn_HHVPD{

	function __construct(){
		// class destructors are not automatically called in HHVM, 
		// so will force in shutdown to ensure Config value are saved
		// also ensures install persisits. 
		register_shutdown_function(array($this, 'Cleanup'));
	}

	function Cleanup(){
		Gdn::Config()->Shutdown();
	}

}

$HHVPDestructor = new Gdn_HHVPD;




