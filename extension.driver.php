<?php
	
	class Extension_Firebug_Profiler extends Extension {
		
		private $params = array();
		
	/*-------------------------------------------------------------------------
		Extension definition
	-------------------------------------------------------------------------*/
		public function about() {
			return array(
				'name'			=> 'Firebug Profiler',
				'version'		=> '1.0',
				'release-date'	=> '2009-05-05',
				'author'		=> array(
					'name'			=> 'Nick Dunn',
					'website'		=> 'http://airlock.com',
					'email'			=> 'nick.dunn@airlock.com'
				),
				'description'	=> 'View Symphony profile and debug information in Firebug.'
			);
		}
		
		public function getSubscribedDelegates() {
			return array(
			
				array(
					'page' => '/system/preferences/',
					'delegate' => 'AddCustomPreferenceFieldsets',
					'callback' => 'appendPreferences'
				),
				
				array(
					'page' => '/system/preferences/',
					'delegate' => 'Save',
					'callback' => 'savePreferences'
				),							
				
				array(
					'page' => '/system/preferences/',
					'delegate' => 'CustomActions',
					'callback' => 'toggleFirebugProfiler'
				),
			
				array(
					'page'		=> '/frontend/',
					'delegate'	=> 'FrontendOutputPreGenerate',
					'callback'	=> 'frontendOutputPreGenerate'
				),
				array(
					'page'		=> '/frontend/',
					'delegate'	=>	'FrontendOutputPostGenerate',
					'callback'	=>	'frontendOutputPostGenerate'
				),
				array(
					'page'		=> '/frontend/',
					'delegate'	=>	'FrontendPageResolved',
					'callback'	=>	'frontendPageResolved'
				),
				array(
					'page'		=> '/frontend/',
					'delegate'	=>	'FrontendParamsResolve',
					'callback'	=>	'frontendParamsResolve'
				)
			);
		}
		
		public function appendPreferences($context){

			$group = new XMLElement('fieldset');
			$group->setAttribute('class', 'settings');
			$group->appendChild(new XMLElement('legend', 'Firebug Profiler'));			
			
			$label = Widget::Label();
			$input = Widget::Input('settings[firebug_profiler][enabled]', 'yes', 'checkbox');
			if($this->_Parent->Configuration->get('enabled', 'firebug_profiler') == 'yes') $input->setAttribute('checked', 'checked');
			$label->setValue($input->generate() . ' Send debug and profile headers on page requests');
			$group->appendChild($label);
						
			$group->appendChild(new XMLElement('p', 'When you are logged in, Firebug Profiler will send Debug and Profile headers that can be read with Firebug with each frontend page request.', array('class' => 'help')));
									
			$context['wrapper']->appendChild($group);
						
		}
		
		public function savePreferences($context){
			if(!is_array($context['settings'])) $context['settings'] = array('firebug_profiler' => array('enabled' => 'no'));
			
			elseif(!isset($context['settings']['firebug_profiler'])){
				$context['settings']['firebug_profiler'] = array('enabled' => 'no');
			}		
		}
				
		public function toggleFirebugProfiler($context){
			
			if($_REQUEST['action'] == 'toggle-firebug-profiler'){			
				$value = ($this->_Parent->Configuration->get('enabled', 'firebug_profiler') == 'no' ? 'yes' : 'no');					
				$this->_Parent->Configuration->set('enabled', $value, 'firebug_profiler');
				$this->_Parent->saveConfig();
				redirect((isset($_REQUEST['redirect']) ? URL . '/symphony' . $_REQUEST['redirect'] : $this->_Parent->getCurrentPageURL() . '/'));
			}
			
		}
		
	/*-------------------------------------------------------------------------
		Delegates:
	-------------------------------------------------------------------------*/
		
		public function FrontendOutputPreGenerate($context) {
			$this->xml = $context['xml'];
		}
	
		public function frontendOutputPostGenerate($context) {

			// don't output anything for unauthenticated users or if profiler is disabled
			if (!Frontend::instance()->isLoggedIn() || $this->_Parent->Configuration->get('enabled', 'firebug_profiler') == 'no') return;
		
			require_once(EXTENSIONS . '/firebug_profiler/lib/FirePHPCore/FirePHP.class.php');
			$firephp = FirePHP::getInstance(true);
		
			// Profile group
			$firephp->group('Profile', array('Collapsed' => false));
			
				$table = array();
				$table[] = array('Task', 'Time');
				foreach(Frontend::instance()->Profiler->retrieveGroup('General') as $profile) {
					$table[] = array($profile[0], $profile[1] . 's');
				}
				$firephp->table('General', $table);
			
				$datasources = Frontend::instance()->Profiler->retrieveGroup('Datasource');
				if (count($datasources) > 0) {
					$table = array();
					$table[] = array('Data Source', 'Time', 'Queries');
					foreach(Frontend::instance()->Profiler->retrieveGroup('Datasource') as $profile) {
						$table[] = array($profile[0], $profile[1] . 's', $profile[4]);
					}
					$firephp->table('Data Sources', $table);
				}			
			
				$events = Frontend::instance()->Profiler->retrieveGroup('Event');
			
				if (count($events) > 0) {
					$table = array();
					$table[] = array('Event', 'Time', 'Queries');
					foreach(Frontend::instance()->Profiler->retrieveGroup('Event') as $profile) {
						$table[] = array($profile[0], $profile[1] . 's', $profile[4]);
					}
					$firephp->table('Events', $table);
				}				
		
			$firephp->groupEnd();
		
			// Debug group
			
			$xml = simplexml_load_string($this->xml);

			$firephp->group('Debug', array('Collapsed' => false));
			
				$table = array();
				$table[] = array('Data Source', 'XML');		
				$xml_events = $xml->xpath('/data/events/*');
				if (count($xml_events) > 0) {
					foreach($xml_events as $event) {
						$table[] = array($event->getName(), $event->asXML());
					}
					$firephp->table('Events', $table);
				}				
			
				$table = array();
				$table[] = array('Data Source', 'XML');		
				$xml_datasources = $xml->xpath('/data/*[name() != "events"]');
				if (count($xml_datasources) > 0) {
					foreach($xml_datasources as $ds) {
						$table[] = array($ds->getName(), $ds->asXML());
					}
					$firephp->table('Data Sources', $table);
				}
				
				$param_table = array();
				$param_table[] = array('Parameter', 'Value');

				foreach($this->params as $name => $value) {
					if ($name == 'root') continue;
					$param_table[] = array('$' . trim($name), ($value == null) ? '' : $value);
				}

				$firephp->table('Page Parameters', $param_table);
				
			$firephp->groupEnd();
				
		}
	
		public function frontendParamsResolve($context) {
			$this->params = $context['params'];
		}
		
	}