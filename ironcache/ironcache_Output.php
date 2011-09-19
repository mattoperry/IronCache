<?php

/**
 * Ironcache Output Display Class
 *
 * @package		Ironcache
 * @author		Matt Perry
 * @link		http://gristlabs.com
 */
 
 class Ironcache_Output extends EE_Output {
 
 	/**
	 * Display the final output -- ads a hook before final display
	 *
	 * @access	public
	 * @return	void
	 */
	 
	public $o;
	 
	function _display($output = '')
	{
		$EE =& get_instance();
		
		//guarantees that what we are about to output is available as a class variable
		$this->o = ($output) ? $output : $this->final_output;
		        
        // -------------------------------------------
        // 'output_start' hook.
        //  - override output behavior
        $edata = $EE->extensions->call('output_start', $this);
        if ($EE->end_script === TRUE) return;
        //
        // -------------------------------------------

		parent::_display($output);	
	}
 
 }