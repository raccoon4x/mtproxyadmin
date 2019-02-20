<?php

class MTProxyAdmin extends \danog\MadelineProto\EventHandler
{

    const DRPROXYBOT = 1626995;
    const MTPROXYBOT = 571465504;

    private $stack = [];
    private $lock = false;

    private $message;

    public function __construct($MadelineProto)
    {
        parent::__construct($MadelineProto);
    }

    public function onAny($update)
    {
        \danog\MadelineProto\Logger::log("Received an update of type ".$update['_']);
    }

    public function onLoop()
    {
        \danog\MadelineProto\Logger::log("Working...");
    }

    public function onUpdateNewChannelMessage($update)
    {
        $this->onUpdateNewMessage($update);
    }

    public function onUpdateEditMessage($update)
    {
        // Игнорировать исходящие сообщения
        if (isset($update['message']['out']) && $update['message']['out']) {
            return;
        }
        // Игнорируем если не можем определить от кого сообщение
        if(!isset($update['message']['from_id'])){
            return;
        }
        if(isset($update['message']['message'])){
            $this->message = $update['message']['message'];
        }
        if ($update['message']['from_id'] === self::MTPROXYBOT){
            if ($this->need_stat !== null) {
                if(strpos($update['message']['message'], 'Promoted channel:')!==false){
                    $bytes = $this->findProxyButtonByHash($update, 'Stats');
                    if($bytes) {
                        $botCallbackAnswer = $this->messages->getBotCallbackAnswer(
                            [
                                'game' => false,
                                'peer' => self::MTPROXYBOT,
                                'msg_id' => $update['message']['id'],
                                'data' => base64_decode($bytes),
                            ]
                        );
                    }
                }
                if(strpos($update['message']['message'], 'Stats for proxy with tag')!==false
                || strpos($update['message']['message'], 'Sorry, we don\'t have stats for your proxy yet.')!==false){
                    $this->forwardMessage(self::DRPROXYBOT, $update['message']['from_id'], [$update['message']['id']]);
                    $this->need_stat = null;
                }
            }
            if(is_array($this->setchannel)){
                if(strpos($update['message']['message'], 'Promoted channel:')!==false) {
                    $bytes = $this->findProxyButtonByHash($update, 'Set promotion');
                    if($bytes) {
                        $botCallbackAnswer = $this->messages->getBotCallbackAnswer(
                            [
                                'game' => false,
                                'peer' => self::MTPROXYBOT,
                                'msg_id' => $update['message']['id'],
                                'data' => base64_decode($bytes),
                            ]
                        );
                    }
                }
            }
        }
    }

    /**
     * @param $update
     */
    public function onUpdateNewMessage($update)
    {
        //\danog\MadelineProto\Logger::log(json_encode($update, JSON_PRETTY_PRINT));

        // Игнорировать исходящие сообщения
        if (isset($update['message']['out']) && $update['message']['out']) {
            return;
        }
        // Игнорируем если не можем определить от кого сообщение
        if (!isset($update['message']['from_id'], $update['message']['message'])) {
            return;
        }
        $this->message = $update['message']['message'];
        if ($update['message']['from_id'] === self::DRPROXYBOT) {
            $this->onUpdateDRProxyBot($update);
        }
        if ($update['message']['from_id'] === self::MTPROXYBOT) {
            $this->newMessageMTProxyBot($update);
        }
    }


    private function onUpdateDRProxyBot($update)
    {
        $this->addTask($update['message']['message']);
        if($this->assertText('/wipe')){
            unset($this->stack);
            $this->disableLock();
        }
        \danog\MadelineProto\Logger::log($this->stack);
        if($this->hasTasks()){
            $this->enableLock();
            if($this->getCurrentCommand() === 'newproxy'){
                if($this->getCurrentStep() === 0){
                    $this->sendMessage(self::MTPROXYBOT, '/newproxy');
                    $this->incStep();
                }
            }
            if (in_array($this->getCurrentCommand(), ['stat', 'setpromo'])){
                if($this->getCurrentStep() === 0){
                    $this->sendMessage(self::MTPROXYBOT, '/myproxies');
                    $this->incStep();
                }
            }
        }else{
            $this->disableLock();
        }
    }

    private function newMessageMTProxyBot($update)
    {
        if($this->hasTasks()){
            $this->enableLock();
            if($this->assertText('Sorry, too many attempts.')){
                $answer = ['to'=>$this->getCurrentTask()['from'], 'message'=>'Sorry, too many attempts. Please try again later.'];
                $answer = json_encode($answer);
                $this->sendMessage(self::DRPROXYBOT, $answer);
                $this->deleteCurrentTask();
            }
            if($this->getCurrentCommand() === 'newproxy'){
                if($this->getCurrentStep() === 1){
                    if($this->assertText('Registering a new proxy server.')){
                        $message = $this->getCurrentTask()['server'].':'.$this->getCurrentTask()['port'];
                        $this->sendMessage(self::MTPROXYBOT, $message);
                        $this->incStep();
                    }
                }
                if($this->getCurrentStep() === 2){
                    if($this->assertText('Now please specify its secret in hex format')){
                        $message = $this->getCurrentTask()['secret'];
                        $this->sendMessage(self::MTPROXYBOT, $message);
                        $this->incStep();
                    }
                }
                if($this->getCurrentStep() === 3){
                    if($this->assertText('Your proxy has been successfully registered.')){
                        $message = $this->message;
                        $answer = ['to'=>$this->getCurrentTask()['from'], 'message'=>$message];
                        $answer = json_encode($answer);
                        $this->sendMessage(self::DRPROXYBOT, $answer);
                        $this->deleteCurrentTask();
                    }
                    if($this->assertText('Incorrect secret value.')){
                        $message = $this->message;
                        $answer = ['to'=>$this->getCurrentTask()['from'], 'message'=>$message];
                        $answer = json_encode($answer);
                        $this->sendMessage(self::DRPROXYBOT, $answer);
                        $this->deleteCurrentTask();
                    }
                }
            }
        }



        if (is_array($this->newproxy)) {
            if ($this->isCommand($update['message']['message'], 'Registering a new proxy server.')) {
                $message = $this->newproxy['server'] . ':' . $this->newproxy['port'];
                $this->sendMessage(self::MTPROXYBOT, $message);
            }
            if ($this->isCommand($update['message']['message'], 'Now please specify its secret in hex format')) {
                $message = $this->newproxy['secret'];
                $this->sendMessage(self::MTPROXYBOT, $message);
            }
            if ($this->isCommand($update['message']['message'], 'Success!')) {
                $message = 'Новые прокси успешно зарегистрированы.'.PHP_EOL;
                if(preg_match('/software\syou\sare\susing\:\s(?<hash>.*?)\./', $update['message']['message'], $match)){
                    $message.= 'Хэш: '.$match['hash'];
                }else{
                    $message.= 'Ошибка при получении хэша проксей.';
                }
                $this->sendMessage(self::DRPROXYBOT, $message, $this->last_msg_id);
                $this->newproxy = null;
                $this->last_msg_id = null;
            }
        }
        if($this->need_stat!==null){
            if(strpos($update['message']['message'], 'Here is the list of all proxies you created:', 0)===0){
                $bytes = $this->findProxyButtonByHash($update, $this->need_stat);
                if($bytes){
                    $botCallbackAnswer = $this->messages->getBotCallbackAnswer(
                        [
                            'game' => false,
                            'peer' => self::MTPROXYBOT,
                            'msg_id' => $update['message']['id'],
                            'data' => base64_decode($bytes),
                        ]
                    );
                }else{
                    $this->sendMessage(self::DRPROXYBOT, 'Не найдено', $this->last_msg_id);
                    $this->need_stat = null;
                }
            }
        }
        if(is_array($this->setchannel)){
            if(strpos($update['message']['message'], 'Here is the list of all proxies you created:', 0)===0){
                $bytes = $this->findProxyButtonByHash($update, $this->setchannel['hash']);
                if($bytes){
                    $botCallbackAnswer = $this->messages->getBotCallbackAnswer(
                        [
                            'game' => false,
                            'peer' => self::MTPROXYBOT,
                            'msg_id' => $update['message']['id'],
                            'data' => base64_decode($bytes),
                        ]
                    );
                }else{
                    $this->sendMessage(self::DRPROXYBOT, 'Не найдено', $this->last_msg_id);
                    $this->setchannel = null;
                }
            }
            if(strpos($update['message']['message'], 'Here is the list of all proxies you created:', 0)===0) {
                $this->sendMessage(self::MTPROXYBOT, $this->setchannel['channel'], $update['message']['id']);
            }
            if(strpos($update['message']['message'], 'New promoted channel has been set', 0)===0){
                $this->forwardMessage(self::DRPROXYBOT, $update['message']['from_id'], [$update['message']['id']]);
                $this->setchannel = null;
            }
        }
    }

    /**
     * @param $text
     *
     * @return bool
     */
    private function assertText($text)
    {
        return strpos($this->message, $text) !== false;
    }

    /**
     * Проверяет, что пришла команда
     *
     * @param $message
     * @param $cmd
     *
     * @return bool
     */
    private function isCommand($message, $cmd)
    {
        return strpos($message, $cmd, 0) === 0;
    }

    /**
     * Отправить сообщение пользователю
     *
     * @param $peer
     * @param $message
     */
    private function sendMessage($peer, $message, $reply_to_msg_id = null)
    {
        try{
            $this->messages->sendMessage(
                [
                    'peer'      => $peer,
                    'message'   => $message,
                    'reply_to_msg_id' => $reply_to_msg_id,
                ]
            );
        }catch(\danog\MadelineProto\RPCErrorException $e){
            \danog\MadelineProto\Logger::log($e);
        }
    }

    private function forwardMessage($peer, $from_peer, array $message_ids)
    {
        try{
            $this->messages->forwardMessages(
                [
                    'to_peer'       => $peer,
                    'from_peer'     => $from_peer,
                    'id'            => $message_ids
                ]
            );
        }catch (\danog\MadelineProto\RPCErrorException $e){
            \danog\MadelineProto\Logger::log($e);
        }
    }

    private function findProxyButtonByHash($update, $hash)
    {
        $update = json_encode($update, JSON_PRETTY_PRINT);
        $update = json_decode($update, true);
        $rows = $update['message']['reply_markup']['rows'];
        foreach ($rows as $key){
            foreach ($key['buttons'] as $button){
                if(strpos($button['text'], $hash) !== false){
                    return $button['data']['bytes'];
                }
            }
        }
        return false;
    }

    /**
     *
     *
     * @param $item
     */
    private function addTask($item)
    {
        if($this->isValidTask($item)){
            $arr = json_decode($item, true);
            $arr['step'] = 0;
            $this->stack[] = $arr;
        }
    }

    private function isValidTask($item)
    {
        $arr = json_decode($item, true);
        if(json_last_error()!==0){
            return false;
        }
        return isset($arr['command']);
    }


    private function setLock(bool $status)
    {
        $this->lock = $status;
    }

    private function enableLock()
    {
        $this->setLock(true);
    }

    private function disableLock()
    {
        $this->setLock(false);
    }

    /**
     * @return bool
     */
    private function getLock(): bool
    {
        return $this->lock;
    }

    private function removeTask()
    {

    }

    /**
     * @return array
     */
    private function getCurrentTask()
    {
        return current($this->stack);
    }

    private function hasTasks(): bool
    {
        if (!empty($this->stack)){
            return true;
        }
        return false;
    }

    /**
     * @return string
     */
    private function getCurrentCommand(): string
    {
        return current($this->stack)['command'];
    }

    /**
     * @return int
     */
    private function getCurrentStep(): int
    {
        return current($this->stack)['step'];
    }

    private function incStep()
    {
        $this->stack[key($this->stack)]['step']++;
    }

    private function deleteCurrentTask()
    {
        $this->lock = false;
        unset($this->stack[key($this->stack)]);
    }

}