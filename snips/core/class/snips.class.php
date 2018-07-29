<?php
require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';

// ini_set("display_errors","On");
// error_reporting(E_ALL);

class snips extends eqLogic

{
    public static

    function resetMqtt()
    {
        $cron = cron::byClassAndFunction('snips', 'mqttClient');
        if (is_object($cron)) {
            $cron->stop();
            $cron->remove();
        }

        $cron = cron::byClassAndFunction('snips', 'mqttClient');
        if (!is_object($cron)) {
            $cron = new cron();
            $cron->setClass('snips');
            $cron->setFunction('mqttClient');
            $cron->setEnable(1);
            $cron->setDeamon(1);
            $cron->setSchedule('* * * * *');
            $cron->setTimeout('1440');
            $cron->save();
            snips::debug('[Reset MQTT] Created snips cron: mqttClient');
        }
    }

    public static

    function health()
    {
        $return = array();
        $socket = socket_create(AF_INET, SOCK_STREAM, 0);
        $addr = config::byKey('mqttAddr', 'snips', '127.0.0.1');
        $port = 1883;
        $server = socket_connect($socket, $addr, $port);
        $return[] = array(
            'test' => __('Mosquitto', __FILE__) ,
            'result' => ($server) ? __('OK', __FILE__) : __('NOK', __FILE__) ,
            'advice' => ($server) ? '' : __('Indique si Mosquitto est disponible', __FILE__) ,
            'state' => $server,
        );
        return $return;
    }

    public static

    function deamon_info()
    {
        $return = array();
        $return['log'] = '';
        $return['state'] = 'nok';
        $cron = cron::byClassAndFunction('snips', 'mqttClient');
        if (is_object($cron) && $cron->running()) {
            $return['state'] = 'ok';
        }

        $dependancy_info = self::dependancy_info();
        if ($dependancy_info['state'] == 'ok') {
            $return['launchable'] = 'ok';
        }

        return $return;
    }

    public static

    function deamon_start($_debug = false)
    {
        self::deamon_stop();
        $deamon_info = self::deamon_info();
        if ($deamon_info['launchable'] != 'ok') {
            throw new Exception(__('Please check your configuration', __FILE__));
        }

        $cron = cron::byClassAndFunction('snips', 'mqttClient');
        if (!is_object($cron)) {
            throw new Exception(__('Can not find task corn ', __FILE__));
        }

        $cron->run();
    }

    public static

    function deamon_stop()
    {
        $cron = cron::byClassAndFunction('snips', 'mqttClient');
        if (!is_object($cron)) {
            throw new Exception(__('Can not find taks corn', __FILE__));
        }

        $cron->halt();
    }

    public static

    function dependancy_info()
    {
        $return = array();
        $return['log'] = 'SNIPS_dep';
        $return['state'] = 'nok';
        $cmd = "dpkg -l | grep mosquitto";
        exec($cmd, $output, $return_var);
        $libphp = extension_loaded('mosquitto');
        if ($output[0] != "" && $libphp) {
            $return['state'] = 'ok';
        }

        return $return;
    }

    public static

    function dependancy_install()
    {
        self::debug('Installation of dependences');
        $resource_path = realpath(dirname(__FILE__) . '/../../resources');
        passthru('sudo /bin/bash ' . $resource_path . '/install.sh ' . $resource_path . ' > ' . log::getPathToLog('SNIPS_dep') . ' 2>&1 &');
        return true;
    }

    public static

    function mqttClient()
    {
        $addr = config::byKey('mqttAddr', 'snips', '127.0.0.1');
        $port = 1883;
        snips::debug('[MQTT] Connection, Host: ' . $addr . ', Port: ' . $port);
        $client = new Mosquitto\Client(); 
        $client->onConnect('snips::connect');
        $client->onDisconnect('snips::disconnect');
        $client->onSubscribe('snips::subscribe');
        $client->onMessage('snips::message');
        $client->onLog('snips::logmq');
        $client->setWill('/jeedom', "Client died :-(", 1, 0);
        try {
            $client->connect($addr, $port, 60);
            $topics = array();
            $topics = snips::getTopics();
            foreach($topics as $topic) {
                $client->subscribe($topic, 0);
            }

            $client->loopForever();
            // while (true) {
            //     $client->loop();
            // }
        }

        catch(Exception $e) {
            snips::debug('[MQTT] Exception: '.$e->getMessage());
        }
    }

    public static

    function connect($r, $message)
    {
        snips::debug('[MQTT] Connected, code: ' . $r . ' message: ' . $message);
        config::save('status', '1', 'snips');
    }

    public static

    function disconnect($r)
    {
        snips::debug('[MQTT] Disconnected, code: ' . $r);
        config::save('status', '0', 'snips');
    }

    public static

    function subscribe()
    {
        log::add('snips', 'inof', '[MQTT] Subscribeed to .'.$topic);
    }

    public static

    function logmq($code, $str)
    {
        if (strpos($str, 'PINGREQ') === false && strpos($str, 'PINGRESP') === false) {
            snips::debug('[MQTT] code: '.$code . ' : ' . $str);
        }
    }

    public static

    function message($message)
    {
        $topics = snips::getTopics();
        $intents_slots = snips::getIntents();
        snips::debug('[MQTT] Received message.');
        if (in_array($message->topic, $topics) == false) {
            return;
        }
        else {
            snips::findAndDoAction(json_decode($message->payload));
        }
    }

    public static

    function sayFeedback($text, $session_id = null, $lang = 'en_GB')
    {
        if ($session_id == null) {
            $topic = 'hermes/tts/say';
            $payload = array(
                'text' => str_replace('{#}', 'Value', $text) ,
                "siteId" => "default",
                "lang" => $lang
            );
            snips::debug('[MQTT] Publish: '.$text);
            self::publish($topic, json_encode($payload));
        }
        else {
            $topic = 'hermes/dialogueManager/endSession';
            $payload = array(
                'text' => $text,
                "sessionId" => $session_id
            );
            snips::debug('[MQTT] Publish: '.$text);
            self::publish($topic, json_encode($payload));
        }
    }

    public static

    function generateFeedback($org_text, $vars, $test_play = false)
    {
        snips::debug('[TTs] Generating feedback text');
        $string_subs = explode('{#}', $org_text);
        $speaking_text = '';
        if (!empty($string_subs)) {
            foreach($string_subs as $key => $sub) {
                if (isset($vars[$key])) {
                    $cmd = cmd::byString($vars[$key]);
                    snips::debug('[TTs] The '.$key.' variable cmd id: ' . $cmd->getId());
                    if (is_object($cmd)) {
                        if ($cmd->getName() == 'intensity_percent' || $cmd->getName() == 'intensity_percentage') {
                            $sub.= $cmd->getConfiguration('orgVal');
                        }
                        else {
                            if ($cmd->getValue()) {
                                $sub.= $cmd->getValue();
                            }
                            else {
                                $sub.= $cmd->getCache('value', 'NULL');
                            }
                        }
                    }
                }
                else {
                    snips::debug('[TTs] The '.$key.' variable cmd is not set');
                    $sub.= '';
                }
                $speaking_text.= $sub;
            }

            return $speaking_text;
        }
        else {
            return $org_text;
        }
    }

    public static

    function publish($topic, $payload)
    {   
        $addr = config::byKey('mqttAddr', 'snips', '127.0.0.1');
        $port = 1883;
        $client = new Mosquitto\Client();
        $client->connect($addr, $port, 60);
        $client->publish($topic, $payload);
        for ($i = 0; $i < 100; $i++) {
            $client->loop(1);
        }

        $client->disconnect();
        unset($client);
    }

    public static

    function parseSessionId($message)
    {
        $data = json_decode($message);
        return $data['sessionId'];
    }

    public static

    function parseSlots($message)
    {
        $data = json_decode($message);
        return $data["slots"];
    }

    public static

    function debug($info)
    {
        log::add("snips", 'debug', $info);
    }

    public static

    function getIntents()
    {
        $intents_file = dirname(__FILE__) . '/../../assistant.json';
        $json_string = file_get_contents($intents_file);
        $json_obj = json_decode($json_string, true);
        $intents = $json_obj["intents"];
        $intents_slots = array();
        foreach($intents as $intent) {
            $slots = array();
            foreach($intent["slots"] as $slot) {
                if ($slot["required"] == true) {
                    $slots[] = $slot["name"];
                }
                else {
                    $slots[] = $slot["name"];
                }
            }

            $intents_slots[$intent["id"]] = $slots;
            unset($slots);
        }

        return json_encode($intents_slots);
    }

    public static

    function getTopics()
    {
        $intents = json_decode(self::getIntents() , true);
        $topics = array();
        foreach($intents as $intent => $slot) {
            array_push($topics, 'hermes/intent/' . $intent);
        }

        return $topics;
    }

    public static

    function reloadAssistant()
    {   
        snips::debug('[Load Assistant] Assistant is being reloaded!');
        $assistant_file = dirname(__FILE__) . '/../../assistant.json';
        $json_string = file_get_contents($assistant_file);
        $assistant = json_decode($json_string, true);
        
        $obj = object::byName('Snips-Intents');
        if (!isset($obj) || !is_object($obj)) {
            $obj = new object();
            snips::debug('[Load Assistant] Created object: Snips-Intents');
        }

        $obj->setName('Snips-Intents');
        $obj->setIsVisible(1);
        $obj->setConfiguration('id', $assistant["id"]);
        $obj->setConfiguration('name',$assistant["name"]);
        $obj->setConfiguration('hotword', $assistant['hotword']);
        $obj->setConfiguration('language', $assistant['language']);
        $obj->setConfiguration('createdAt', $assistant['createdAt']);
        $obj->save();

        foreach($assistant['intents'] as $intent) {
            if (is_object(snips::byLogicalId($intent['id'], 'snips'))) {
                return;
            }
            else {
                $elogic = new snips();
                $elogic->setEqType_name('snips');
                $elogic->setLogicalId($intent['id']);
                $elogic->setName($intent['name']);
                $elogic->setIsEnable(1);
                $elogic->setConfiguration('snipsType', 'Intent');
                $elogic->setConfiguration('slots', $intent['slots']);
                $elogic->setConfiguration('isSnipsConfig', 1);
                $elogic->setConfiguration('isInteraction', 0);
                $elogic->setConfiguration('language', $intent['language']);
                $elogic->setObject_id(object::byName('Snips-Intents')->getId());
                $elogic->save();
                snips::debug('[Load Assistant] Created intent entity: '.$intent['name']);
            }
        }
    }

    public static

    function deleteAssistant()
    {
        $obj = object::byName('Snips-Intents');
        if (is_object($obj)) {
            $obj->remove();
            snips::debug('[Remove Assistant] Removed object: Snips-Intents');
        }

        $eqLogics = eqLogic::byType('snips');
        foreach($eqLogics as $eq) {
            $cmds = snipsCmd::byEqLogicId($eq->getLogicalId);
            foreach($cmds as $cmd) {
                snips::debug('[Remove Assistant] Removed slot cmd: '.$cmd->getName());
                $cmd->remove();
            }
            snips::debug('[Remove Assistant] Removed intent entity: '.$eq->getName());
            $eq->remove();
        }
    }

    public static

    function findAndDoAction($payload)
    {
        $intent_name = $payload->{'intent'}->{'intentName'};
        $site_id = $payload->{'siteId'};
        $session_id = $payload->{'sessionId'};
        $query_input = $payload->{'input'};
        snips::debug('[Binding Execution] Intent:' . $intent_name . ' siteId:' . $site_id . ' sessionId:' . $session_id);
        $slots_values = array();

        foreach($payload->{'slots'} as $slot) {
            if (is_string($slot->{'value'}->{'value'})) {
                $slots_values[$slot->{'slotName'}] = strtolower(str_replace(' ', '', $slot->{'value'}->{'value'}));
            }
            else {
                $slots_values[$slot->{'slotName'}] = $slot->{'value'}->{'value'};
            }
        }

        snips::setSlotsCmd($slots_values, $intent_name);
        $eqLogic = eqLogic::byLogicalId($intent_name, 'snips');
        $bindings = $eqLogic->getConfiguration('bindings');
        if (!$eqLogic->getConfiguration('isSnipsConfig') && $eqLogic->getConfiguration('isInteraction')) {
            $param = array();
            $reply = interactQuery::tryToReply($query_input, $param);
            snips::sayFeedback($reply['reply'], $session_id);
        }
        else {
            $bindings_match_coming_slots = array();
            foreach($bindings as $binding) {
                snips::debug('[Binding Execution] Cur binding name : ' . $binding['name']);
                snips::debug('[Binding Execution] Binding count is : ' . count($binding['nsr_slots']));
                snips::debug('[Binding Execution] Snips count is : ' . count($slots_values));
                if (count($binding['nsr_slots']) === count($slots_values)) {
                    snips::debug('[Binding Execution] Binding has corr number of slot: ' . $binding['name']);
                    $slot_all_exists_indicator = 1;
                    foreach($binding['nsr_slots'] as $slot) {
                        if (array_key_exists($slot, $slots_values)) {
                            $slot_all_exists_indicator*= 1;
                        }
                        else {
                            $slot_all_exists_indicator*= 0;
                        }
                    }

                    if ($slot_all_exists_indicator) {
                        $bindings_match_coming_slots[] = $binding;
                    }
                }
            }

            $bindings_with_correct_condition = array();
            foreach($bindings_match_coming_slots as $bindings_match_coming_slot) {
                if (!empty($bindings_match_coming_slot['condition'])) {
                    $condition_all_true_indicator = 1;
                    foreach($bindings_match_coming_slot['condition'] as $condition) {
                        $cmd = cmd::byId($condition['pre']);
                        if (is_string($cmd->getCache('value', 'NULL'))) {
                            $pre_value = strtolower(str_replace(' ', '', $cmd->getCache('value', 'NULL')));
                        }
                        else {
                            $pre_value = $cmd->getCache('value', 'NULL');
                        }

                        if (is_string($condition['aft'])) {
                            $aft_value = strtolower(str_replace(' ', '', $condition['aft']));
                        }
                        else {
                            $aft_value = $condition['aft'];
                        }

                        snips::debug('[Binding Execution][Condition] Condition Aft value is : ' . $aft_value);
                        if ($pre_value == $aft_value) {
                            $condition_all_true_indicator*= 1;
                        }
                        else {
                            $condition_all_true_indicator*= 0;
                        }
                    }

                    if ($condition_all_true_indicator) {
                        if ($bindings_match_coming_slot['enable']) {
                            $bindings_with_correct_condition[] = $bindings_match_coming_slot;
                        }
                    }
                }
                else {
                    if ($bindings_match_coming_slot['enable']) {
                        $bindings_with_correct_condition[] = $bindings_match_coming_slot;
                    }
                }
            }

            foreach($bindings_with_correct_condition as $binding) {
                foreach($binding['action'] as $action) {
                    $options = $action['options'];
                    if (true) {
                        snips::setSlotsCmd($slots_values, $intent_name, $options);
                        scenarioExpression::createAndExec('action', $action['cmd'], $options);
                    }
                    else {
                        snips::debug('[Binding Execution] Found binding action, but it is not enabled');
                    }
                }

                $text = snips::generateFeedback($binding['tts']['text'], (array)$binding['tts']['vars'], false);
                snips::debug('[Binding Execution] Generated text is ' . $text);
                snips::debug('[Binding Execution] Orginal text is ' . $binding['tts']['text']);
                snips::sayFeedback($text, $session_id);
            }
        }

        sleep(1);
        snips::resetSlotsCmd();
    }

    public static

    function setSlotsCmd($slots_values, $intent, $_options = null)
    {
        snips::debug('[Slot Set] Set slots cmd values');
        $eq = eqLogic::byLogicalId($intent, 'snips');
        if (is_object($eq)) {
            foreach($slots_values as $slot => $value) {
                snips::debug('[Slot Set] Slots name is :' . $slot);
                $cmd = $eq->getCmd(null, $slot);
                if ($_options) {
                    snips::debug('[Slot Set] Slots option entered, entityId is:' . $cmd->getConfiguration('entityId'));
                    if ($cmd->getConfiguration('entityId') == 'snips/percentage') {
                        $org = $value;
                        $value = snips::percentageRemap($_options['LT'], $_options['HT'], $value);
                        $cmd->setConfiguration('orgVal', $org);
                        snips::debug('[Slot Set] Slots is percentage, value after convert:' . $value);
                    }
                }

                $eq->checkAndUpdateCmd($cmd, $value);
                $cmd->setValue($value);
                $cmd->save();
            }
        }else{
            snips::debug('[Slot Set] Did not find entiry:' . $intent);
        }
    }

    public static

    function percentageRemap($_LT, $_HT, $_percentage)
    {
        $real_value = ($_HT - $_LT) * ($_percentage / 100);
        if ($real_value > $_HT) {
            $real_value = $_HT;
        }
        else
        if ($real_value < $_LT) {
            $real_value = $_LT;
        }

        return $real_value;
    }

    public static

    function resetSlotsCmd($slots_values = false, $intent = false)
    {
        snips::debug('[Slot Reset] Reset all the slots');
        if ($slots_values == false && $intent == false) {
            $eqs = eqLogic::byType('snips');
            foreach($eqs as $eq) {
                $cmds = $eq->getCmd();
                foreach($cmds as $cmd) {
                    $cmd->setCache('value', null);
                    $cmd->setValue(null);
                    $cmd->setConfiguration('orgVal', null);
                    $cmd->save();
                }
            }
        }
        else {
            $eq = eqLogic::byLogicalId($intent, 'snips');
            foreach($slots_values as $slot => $value) {
                $cmd = $eq->getCmd(null, $slot);
                $cmd->setCache('value');
                $cmd->setValue(null);
                $cmd->setConfiguration('orgVal', null);
                $cmd->save();
            }
        }
    }

    public static

    function exportConfigration($name)
    {
        $binding_conf = array();
        $eqs = eqLogic::byType('snips');
        foreach($eqs as $eq) {
            $org_bindings = $eq->getConfiguration('bindings');
            $json_string = json_encode($org_bindings);
            snips::debug('[Export Config] json_string type is: ' . gettype($json_string));
            preg_match_all('/#[^#]*[0-9]+#/', $json_string, $matches);
            $human_cmd = cmd::cmdToHumanReadable($matches[0]);
            foreach($human_cmd as $key => $cmd_text) {
                $json_string = str_replace($matches[0][$key], $cmd_text, $json_string);
            }

            preg_match_all('/("pre":")[^("pre":")]*[0-9]+"/', $json_string, $matches_c);
            $matches_c1 = $matches_c;
            foreach($matches_c[0] as $key => $value) {
                $matches_c[0][$key] = str_replace('"pre":"', '#', $value);
                snips::debug('[Export Config] 1st : ' . $matches_c[0][$key]);
                $matches_c[0][$key] = str_replace('"', '#', $matches_c[0][$key]);
                snips::debug('[Export Config] 2nd : ' . $matches_c[0][$key]);
            }

            $humand_cond = cmd::cmdToHumanReadable($matches_c[0]);
            foreach($humand_cond as $key => $cmd_text) {
                snips::debug('[Export Config] key word : ' . $matches_c1[0][$key] . ' replace ' . '"pre":"' . $cmd_text . '"');
                $json_string = str_replace($matches_c1[0][$key], '"pre":"' . $cmd_text . '"', $json_string);
            }

            $aft_bindings = json_decode($json_string);
            snips::debug('[Export Config] vars: ' . $aft_bindings);
            $binding_conf[$eq->getLogicalId() ] = $aft_bindings;
        }

        $file = fopen(dirname(__FILE__) . '/../../config_backup/' . $name . '.json', 'w');
        $res = fwrite($file, json_encode($binding_conf));
        if ($res) {
            snips::debug('[Export Config] Success output');
        }
        else {
            snips::debug('[Export Config] Faild output');
        }
    }

    public static

    function importConfigration($configFileName)
    {
        snips::debug('[Import Config] Asked to import file: ' . $configFileName);
        $json_string = file_get_contents(dirname(__FILE__) . '/../../config_backup/' . $configFileName);
        preg_match_all('/("pre":")(#.*?#)(")/', $json_string, $matches);
        $cmd_ids = cmd::humanReadableToCmd($matches[2]);

        foreach($cmd_ids as $key => $cmd_id) {
            $cmd_id = str_replace('#', '', $cmd_id);

            snips::debug('[Import Config] key word : ' . '"pre":"'.$matches[2][$key].'"' . ' replace ' . '"pre":"'.$cmd_id.'"');

            $json_string = str_replace('"pre":"'.$matches[2][$key].'"', '"pre":"'.$cmd_id.'"', $json_string);
        }

        $data = json_decode($json_string, true);
        $eqs = eqLogic::byType('snips');
        foreach($eqs as $eq) {
            if ($data[$eq->getLogicalId() ] != '' && isset($data[$eq->getLogicalId() ])) {
                $eq->setConfiguration('bindings', $data[$eq->getLogicalId() ]);
                $eq->save(true);
            }
        }
    }

    public static

    function displayAvailableConfigurations()
    {
        $command = 'ls ' . dirname(__FILE__) . '/../../config_backup/';
        $res = exec($command, $output, $return_var);
        return $output;
    }

    public static

    function isSnipsRunLocal()
    {
        $addr = config::byKey('mqttAddr', 'snips', '127.0.0.1');
        if ($addr == '127.0.0.1' || $addr == 'localhost');
    }

    public static

    function tryToFetchDefault(){
        $res = snips::fetchAssistantJson('pi', 'raspberry');
        snips::debug('[TryToFetchDefault] Result code: '.$res);
        return $res; 
    }

    public static

    function fetchAssistantJson($usrename, $password)
    {   
        $ip_addr = config::byKey('mqttAddr', 'snips', '127.0.0.1');
        snips::debug('[FetchAssistantJson]Connection : Trying to connect to: '.$ip_addr);
        $connection = ssh2_connect($ip_addr, 22); 
        if (!$connection) {
            snips::debug('[FetchAssistantJson]Connection: Faild code: -2');
            return -2;
        }

        $resp = ssh2_auth_password($connection, $usrename, $password);
        if ($resp) {
            snips::debug('[FetchAssistantJson]Verification: Success');
        }
        else {
            snips::debug('[FetchAssistantJson]Verification: Faild code: -1');
            return -1;
        }

        $res = ssh2_scp_recv($connection, '/usr/share/snips/assistant/assistant.json', dirname(__FILE__) . '/../../assistant.json');
        if ($res) {
            ssh2_exec($connection, 'exit');
            unset($connection);
            snips::debug('[FetchAssistantJson]Fecth resutlt : Success');
            return 1;
        }
        else {
            ssh2_exec($connection, 'exit');
            unset($connection);
            snips::debug('[FetchAssistantJson]Fecth resutlt : Faild code: 0');
            return 0;
        }
    }

    public static 

    function lightBrightnessShift($_cmdStatus, $__cmdAction, $_min, $_max, $_step)
    {
        $cmd = cmd::byString($LIGHT_BRIGHTNESS_VALUE);

        if (is_object($cmd))
        if ($cmd->getValue()) $current_val = $cmd->getValue();
        else $current_val = $cmd->getCache('value', 'NULL');
        $options = array();

        if ($OPERATION === 'UP') $options['slider'] = $current_val + round(($MAX_VALUE - $MIN_VALUE) * $STEP_VALUE);
        else
        if ($OPERATION === 'DOWN') $options['slider'] = $current_val - round(($MAX_VALUE - $MIN_VALUE) * $STEP_VALUE);

        if ($options['slider'] < $MIN_VALUE) $options['slider'] = $MIN_VALUE;

        if ($options['slider'] > $MAX_VALUE) $options['slider'] = $MAX_VALUE;
        fwrite(STDOUT, '[Scenario] Light shift for [' . $LIGHT_BRIGHTNESS_ACTION . '], from -> ' . $options['slider'] . ' to ->' . $current_val . '\n');
        $cmdSet = cmd::byString($LIGHT_BRIGHTNESS_ACTION);

        if (is_object($cmdSet)) $cmdSet->execCmd($options);
    }

    public

    function preInsert()
    {
    }

    public

    function postInsert()
    {
    }

    public

    function preSave()
    {
        $slots = $this->getConfiguration('slots');
        $slotSet = array();
        foreach($slots as $slot) {
            $slotSet[] = $slot['name'];
        }

        $bindings = $this->getConfiguration('bindings');
        foreach($bindings as $key => $binding) {
            $necessary_slots = array();
            $conditions = $binding['condition'];
            foreach($conditions as $condition) {
                if (!in_array(cmd::byId($condition['pre'])->getName() , $necessary_slots)) {
                    $necessary_slots[] = cmd::byId($condition['pre'])->getName();
                }
            }

            $actions = $binding['action'];
            foreach($actions as $action) {
                $options = $action['options'];
                foreach($options as $option) {
                    if (preg_match("/^#.*#$/", $option)) {
                        if (in_array(cmd::byId(str_replace('#', '', $option))->getName() , $slotSet)) {
                            if (!in_array(cmd::byId(str_replace('#', '', $option))->getName() , $necessary_slots)) {
                                $necessary_slots[] = cmd::byId(str_replace('#', '', $option))->getName();
                            }
                        }
                    }
                }
            }

            if (!empty($necessary_slots)) {
                $bindings[$key]['nsr_slots'] = $necessary_slots;
            }

            unset($necessary_slots);
        }

        $this->setConfiguration('bindings', $bindings);
    }

    public

    function postSave()
    {
        $slots = $this->getConfiguration('slots');
        foreach($slots as $slot) {
            $slotCmd = $this->getCmd(null, $slot['name']);
            
            if (!is_object($slotCmd)) {
                snips::debug('[PostSave] Created: ' . $slot['name']);
                $slotCmd = new snipsCmd();
            }

            $slotCmd->setName($slot['name']);
            $slotCmd->setEqLogic_id($this->getId());
            $slotCmd->setLogicalId($slot['name']);
            $slotCmd->setType('info');
            $slotCmd->setSubType('string');
            $slotCmd->setConfiguration('id', $slot['id']);
            $slotCmd->setConfiguration('entityId', $slot['entityId']);
            $slotCmd->setConfiguration('missingQuestion', $slot['missingQuestion']);
            $slotCmd->setConfiguration('required', $slot['required']);
            $slotCmd->save();
        }
    }

    public

    function preUpdate()
    {
    }

    public

    function postUpdate()
    {
    }

    public

    function preRemove()
    {
    }

    public

    function postRemove()
    {
    }
}

class snipsCmd extends cmd

{
}