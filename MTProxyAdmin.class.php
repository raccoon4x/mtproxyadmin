<?php

class MTProxyAdmin extends \danog\MadelineProto\EventHandler
{

    const DRPROXYBOT = 1626995;
    const MTPROXYBOT = 571465504;
    const TAG = '0fbfccfcc2290628c7fefd7030f9f4a7'; // TAG от которого требуется менять пароль

    private $stack = [];
    private $lock = false;

    private $setchannel = [];

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
            if(!empty($this->setchannel)){
                $this->setPromo($update);
                if(strpos($update['message']['message'], 'Promoted channel:')!==false) {
                    $bytes = strpos($update['message']['message'], 'Promoted channel: n/a.') !== false ?
                        $this->findProxyButtonByHash($update, 'Set promotion') :
                        $this->findProxyButtonByHash($update, 'Edit promotion');
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
        if(preg_match('/\/setpromo\s+(?:.*\/)?(?:\@)?(?<channel>\w+)/', $update['message']['message'], $match)){
            $this->setchannel['channel'] = $match['channel'];
            $this->sendMessage(self::MTPROXYBOT, '/myproxies');
        }
    }

    private function newMessageMTProxyBot($update)
    {
        if(!empty($this->setchannel)){
            $this->setPromo($update);
            if(strpos($update['message']['message'], 'This allows you to set up a promoted channel for your proxy', 0)===0) {
                $this->sendMessage(self::MTPROXYBOT, '@'.$this->setchannel['channel'], $update['message']['id']);
            }
            if(strpos($update['message']['message'], 'New promoted channel has been set', 0)===0){
                $this->forwardMessage(self::DRPROXYBOT, $update['message']['from_id'], [$update['message']['id']]);
                $this->setchannel = [];
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
                if(strpos($button['text'], $hash) !== false || strpos($button['text'], '»') !== false){
                    // Если нашли кнопку с нужным текстом, то кликаем на нее, в случае, если есть кнопка листать дальше, листаем
                    return $button['data']['bytes'];
                }
            }
        }
        return false;
    }

    private function setPromo($update)
    {
        if(strpos($update['message']['message'], 'Here is the list of all proxies you created:', 0)===0){
            $bytes = $this->findProxyButtonByHash($update, self::TAG);
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
                $this->sendMessage(self::DRPROXYBOT, 'Can\'t find tag '.self::TAG);
                $this->setchannel = [];
            }
        }
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