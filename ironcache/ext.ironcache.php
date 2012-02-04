<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

/**
 * IronCache -- High performance in-memory caching for ExpressionEngine2
 *
 * @author		GristLabs -- Darcy Christ, Nathan Letsinger and Matt Perry
 * @license		http://www.gnu.org/copyleft/gpl.html
 * @link		http://gristlabs.com/ironcache
 * 
 *		
 * Note: This is software is released to the public under the GPL as a service, and since we are nice.
 * We will do our best to answer what questions we can, but we don't guarantee support for
 * this extension, so use at your own risk.
 *
 * Question?  Step one:  visit and read http://www.gristlabs.com/ironcache  We've tried to cover the basics there
 * If you still have a question or suggestion at that point, you can contact us through that blog.
 *
 */

class Ironcache_ext
{

	/** Extension Basics **/
	
	public $name = 'IronCache';
	public $version = '0.1';
	public $description = 'Caching Layer for ExpressionEngine with Memcached';
	public $docs_url = 'http://gristlabs.com/ironcache';
	public $settings_exist = 'y';
	
	/** Settings **/
	
	public $settings = array('enabled' => '', 'prefix' => '', 'memcached_host' => '', 'memcached_port' => '', 'config' => '');

	
	/** URI  **/
	
	public $uri;
	public $uri_key;

	/** Keys **/
	
	public $prefix;
	public $key_count;
	public $key_page;
	public $key_flag;

    /** Caching variables for the current request, with defaults**/

    public $cache_time = 500;	//in seconds
    public $counter_reset = 30; //in seconds
    public $threshold = 2;
    public $cacheable = false;
    
    
    /** count from cache **/
    
    public $count;
    
    /**our memcache object**/
    
    private $memcache = false;

	
	/**
	 * Ironcache Constructor with settings
	 */
	public function __construct($settings='')
	{
		
		$this->EE =& get_instance();
		$this->settings = ($settings) ? $settings : $this->settings;
		
		//if not provided, assume a port of 11211 -- memcache daemon listens to that port by default, but who knows
		$this->settings['memcached_port'] = (trim($this->settings['memcached_port'])) ? $this->settings['memcached_port'] : 11211;
			
		//load language file	
		$this->EE->lang->loadfile('ironcache');
		
		//get the URI
    $this->uri	= ltrim($_SERVER['REQUEST_URI'],'/');
	    $this->uri_key	= md5($this->uri);

        //parse caching configuration, and while we're at it determine whether we should be cacheing the current request
        $this->_parse_config();
                
        //set the key prefix
		$this->prefix = ($this->settings['prefix']) ? $this->settings['prefix'].'_' : '';
        
        //build the keys for the count, page and flag
		$site_id = $this->EE->config->item('site_id') . '_';
		$key_base = $this->prefix . $site_id . $this->uri_key;        
		$this->key_count = $key_base . '_c';
        $this->key_page = $key_base . '_p';
        $this->key_flag = $key_base . '_f';
	}
	
		
	/** 
	*	Ironcache Activate
	**/
	
	public function activate_extension() 
	{
		
		$data = array(
			'class'		=> __CLASS__,
			'method'	=> 'sessions_end',
			'hook'		=> 'sessions_end',
			'settings'	=> serialize($this->settings),
			'priority'	=> 10,
			'version'	=> $this->version,
			'enabled'	=> 'y'
		);
		
		$this->EE->db->insert('extensions', $data);
		
		$data = array(
			'class'		=> __CLASS__,
			'method'	=> 'output_start',
			'hook'		=> 'output_start',
			'settings'	=> serialize($this->settings),
			'priority'	=> 10,
			'version'	=> $this->version,
			'enabled'	=> 'y'
		);
		
		$this->EE->db->insert('extensions', $data);
	}
	
	/**
	*	Ironcache Update
	**/
	
	public function update_extension($current = '')
	{
		if ($current == '' OR $current == $this->version)
		{
			return FALSE;
		}
		
		if ($current < '0.1')
		{
			// Update to version 0.1
		}
		
		$this->EE->db->where('class', __CLASS__);
		$this->EE->db->update('extensions', array('version' => $this->version));
	}
	
	/**
	*	Ironcache Settings
	**/
	
	public function settings()
	{
		$settings = array();
		
		$settings['enabled']	= array('s', array('y' => 'yes', 'n' => 'no'), 'n');
		$settings['memcached_host'] = array('i', '', '');
		$settings['memcached_port'] = array('i', '', '11211');
		$settings['prefix'] = array('i', '', '');
		$settings['config'] = array('t', array('rows'=>20), '');
			
		return $settings;
	}


	/**
	*	Ironcache sessions_end
	*
	**/
	
	public function sessions_end(&$sess) 
	{
						
		//all of the following must be true to proceed
		//	-cache must be enabled
		//	-page must be cacheable
		//	-there cannot be a logged-in session
		
		if (($this->settings['enabled']!='y') || ($this->cacheable!==true) || ($sess->userdata['username']!='')) {
			return true;
		}
		
		
		//increment the count for this page
		$this->_increment_count();
            
       	//see if we have valid page in cache already
        $page = $this->_get_from_cache($this->key_page);
                     
       	if (!$page) 
       	{

       		//no page is in cache.  If we're at or above the threshold, we flag for caching
       		if($this->_get_from_cache($this->key_count) >= $this->threshold)
       		{

       			$this->_flag_for_caching();
       			
       			
       			//Since we now know that we'll require it, let's make sure there's a hook in our output object so that we can later cache this stuff
       			require_once(PATH_THIRD . 'ironcache/ironcache_Output.php');
				$this->EE->output = new Ironcache_Output;       			
       			
       		}
       		
       		//nothing left to do -- cya at the end of the pageload
       		return true;
       	}
       	
       	//ah, so we do have a valid page from the cache to show.  Let's output it now.
	    
		$this->_show_page($page);
		
	}


	/**
	*	Ironcache output_start
	*
	**/
	
	public function output_start(&$out)
	{


	     if ($this->_is_flagged_for_caching()) {
	     
		 	//cache the page
		 	$this->_cache_page($out);
	     }

		return true;
	}
	
	/**
	*	Ironcache _parse_config
	**/
	
	private function _parse_config() 
	{

		//four conditions must apply to proceed:  
		//	ironcache must not be disabled
		//	we must be looking at a PAGE request and
		//	$_POST must be empty
		//  there must be a non-empty config
        if (($this->settings['enabled'] != 'y') || REQ != 'PAGE' || !empty($_POST) || !trim($this->settings['config'])) {
            return false;
        }
		
		//now we check the actual uri against the settings to determine if the request is cacheable
		
		$configs = explode(PHP_EOL, $this->settings['config']);
						
		foreach ($configs as $config) 
		{
			$t = explode('||', trim($config));
			
			//if the URI pattern of the config line is empty, it's referring to the homepage.
			if ($t[0]=='')
			{
				//the homepage will have an empty trimmed URI
				if ($this->uri == '') 
				{
					$this->cacheable = true;
				}
			}
			
			elseif(preg_match('/' . $t[0] . '/', $this->uri))
			{
				$this->cacheable = true;
			}
		}//foreach
			
		//now we know for sure if this request is cacheable or not.  If it's not, bye bye.
		if (!$this->cacheable) return false;
		
		//so -- the request is cacheable.  Let's set the rest of the settings from the config array
		$this->cache_time = (isset($t[1]) && !empty($t[1])) ? $t[1] : $this->cache_time;
        $this->counter_reset = (isset($t[2]) && !empty($t[2])) ? $t[2] : $this->counter_reset;
        $this->threshold = (isset($t[3]) && !empty($t[3])) ? $t[3] : $this->threshold;
	}
	
	
	/** 
	*	Ironcache _get_from_cache()
	**/
	
	private function _get_from_cache($k) 
	{
		$C = $this->_connect_to_cache();
		return $C->get($k);
	}
	
	/**
	*	Ironcache _increment_count
	**/	
	
	private function _increment_count() 
	{
		
		$C = $this->_connect_to_cache();
				
		//try to increment the count
        if($C->increment($this->key_count)) return TRUE;
        
        //still here?  then we must have to set the count.
        $C->set($this->key_count,1,0,$this->counter_reset);
                
        return TRUE;
	}
	
	/** 
	*	Ironcache _flag_for_caching()
	**/
	
	private function _flag_for_caching() 
	{		
		$C = $this->_connect_to_cache();
		// this flag is set for 30 seconds, but really this is more than enough time, 
		// since the flag only needs to live until the end of the current page request
		$C->set($this->key_flag,'true',0,30);			
	}
	
	/** 
	*	Ironcache _is_flagged_for_caching()
	**/
	
	private function _is_flagged_for_caching() 
	{		
	
		if ($this->_get_from_cache($this->key_flag)) {
			return true;
		}
		
		return false;
	}
	
	/** 
	*	Ironcache _connect_to_cache()
	**/
	
	private function _connect_to_cache() 
	{	
		if (!$this->memcache) 
		{
			$this->memcache = new Memcache;
			$this->memcache->connect($this->settings['memcached_host'],$this->settings['memcached_port']);
		}
		return $this->memcache;
	}
	
	/** 
	*	Ironcache _cache_page($out)
	**/
	
	private function _cache_page(&$out) 
	{		            
        //finally connect to and set the cache
        $C = $this->_connect_to_cache(); 
        $result = $C->set($this->key_page,$out->o,0,$this->cache_time);
        return $result;
	}
	
	
	/** 
	*	Ironcache _show_page($page)
	**/
	
	private function _show_page($page) 
	{
		@header("HTTP/1.1 200 OK", TRUE, 200);
        @header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");
        @header("Last-Modified: ".gmdate("D, d M Y H:i:s")." GMT");
        @header("Pragma: no-cache");
	    @header('X-Ironcache: true');
            
	    // uncomment this to get visual indication that caching is working - otherwise look for X-Ironcache header
	    //echo "THIS PAGE IS CACHED";
	    echo $page;
	    exit;
	}
}

/* End of file ext.ironcache.php */