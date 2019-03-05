<?php

class MTProxyAdmin extends \danog\MadelineProto\EventHandler
{

    const ADMINS = [672150123, 1626995];
    const MTPROXYBOT = 571465504;
    const TAG = '7a616b4a7bde72f938749e312b63bb4d'; // TAG от которого требуется менять пароль по умолчанию

    private $stack = [];
    private $lock = false;

    private $setchannel = [];

    private $from_id;
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
        if($this->hasTasks()) {
            if ($this->getCurrentCommand() === 'promo' && $this->getCurrentStep() === 0) {
                $this->incStep();
                $this->sendMessage(self::MTPROXYBOT, '/myproxies');
            }
        }
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
        if(!isset($update['message']['from_id'], $update['message']['message'])){
            return;
        }
        $this->message = $update['message']['message'];
        $this->from_id = $update['message']['from_id'];
        if ($update['message']['from_id'] === self::MTPROXYBOT){
            if($this->hasTasks()) {
                if ($this->getCurrentCommand() === 'promo') {
                    $this->searchProxy($update);
                    if (strpos($update['message']['message'], 'Promoted channel:') !== false) {
                        // TODO: устранить баг, залипает, если установить тот же самый канал, то бот не редактирует сообщение.
                        if ($this->getCurrentStep() === 2) {
                            $this->forwardMessage($this->getCurrentTaskUser(), self::MTPROXYBOT, [$update['message']['id']]);
                            $this->deleteCurrentTask();
                            return;
                        }
                        $bytes = strpos($update['message']['message'], 'Promoted channel: n/a.') !== false
                            ? $this->findButtonWithText($update, 'Set promotion')
                            : $this->findButtonWithText($update, 'Edit promotion');
                        if ($bytes) {
                            $this->clickOnButton($update['message']['id'], $bytes);
                        }
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
        // Игнорировать исходящие сообщения
        if (isset($update['message']['out']) && $update['message']['out']) {
            return;
        }
        // Игнорируем если не можем определить от кого сообщение
        if (!isset($update['message']['from_id'], $update['message']['message'])) {
            return;
        }
        $this->message = $update['message']['message'];
        $this->from_id = $update['message']['from_id'];
        if ($this->isAdmin()) {
            $this->onUpdateDRProxyBot($update);
        }
        if ($this->from_id === self::MTPROXYBOT) {
            $this->newMessageMTProxyBot($update);
        }
    }


    private function onUpdateDRProxyBot($update)
    {
        if(preg_match('/\/promo\s+(?:.*\/)?(?:\@)?(?<channel>\w+)(?:\s+)?(?<tag>\w+)?/', $update['message']['message'], $match)){
            $tag = self::TAG;
            if(isset($match['tag'])){
                if(strlen($match['tag']) !== 32) {
                    $this->sendMessage($this->from_id, 'Error! The third argument TAG must be 32 characters long.');
                    return;
                }
                $tag = $match['tag'];
            }
            $this->setchannel['channel'] = $match['channel'];
            $task = $this->prepareTask($match['channel'], $tag);
            $this->addTask($task);
            //$this->sendMessage(self::MTPROXYBOT, '/myproxies');
        }
        if($this->assertText('/tasks')){
            if(!empty($this->stack)) {
                $this->sendMessage($this->from_id, json_encode($this->stack, JSON_PRETTY_PRINT));
            }else{
                $this->sendMessage($this->from_id, 'Tasks is empty...');
            }
        }
        if($this->assertText('/wipe')){
            $this->stack = [];
            $this->sendMessage($this->from_id, 'Tasks is clear...');
        }
    }

    private function newMessageMTProxyBot($update)
    {
        if($this->hasTasks()) {
            if ($this->getCurrentCommand() === 'promo') {
                $this->searchProxy($update);
                if (strpos($update['message']['message'], 'This allows you to set up a promoted channel for your proxy', 0)
                    === 0) {
                    $this->sendMessage(
                        self::MTPROXYBOT, '@' . $this->getCurrentTaskChannel(), $update['message']['id']
                    );
                }
                if (strpos($update['message']['message'], 'New promoted channel has been set', 0) === 0) {
                    $this->incStep();
                    $this->forwardMessage($this->getCurrentTaskUser(), $update['message']['from_id'], [$update['message']['id']]);
                    //$this->deleteCurrentTask();
                }
                if ($this->assertText('Sorry, this username doesn\'t point to a channel.')) {
                    $this->forwardMessage($this->getCurrentTaskUser(), $update['message']['from_id'], [$update['message']['id']]);
                    $this->deleteCurrentTask();
                }
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

    private function clickOnButton($msg_id, $bytes)
    {
        try{
            $this->messages->getBotCallbackAnswer(
                [
                    'game' => false,
                    'peer' => self::MTPROXYBOT,
                    'msg_id' => $msg_id,
                    'data' => base64_decode($bytes),
                ]
            );
        }catch (\danog\MadelineProto\RPCErrorException $e){
            \danog\MadelineProto\Logger::log($e);
        }
    }

    private function findButtonWithText($update, $text)
    {
        $update = json_encode($update, JSON_PRETTY_PRINT);
        $update = json_decode($update, true);
        $rows = $update['message']['reply_markup']['rows'];
        foreach ($rows as $key){
            foreach ($key['buttons'] as $button){
                if(strpos($button['text'], $text) !== false){
                    // Если нашли кнопку с нужным текстом, то кликаем на нее, в случае, если есть кнопка листать дальше, листаем
                    return $button['data']['bytes'];
                }
            }
        }
        return false;
    }

    private function searchProxy($update)
    {
        if(strpos($update['message']['message'], 'Here is the list of all proxies you created:', 0)===0){
            $bytes = $this->findButtonWithText($update, $this->getCurrentTaskTag());
            if($bytes) {
                $this->clickOnButton($update['message']['id'], $bytes);
            }else{
                $bytes = $this->findButtonWithText($update, '»');
                if($bytes){
                    $this->clickOnButton($update['message']['id'], $bytes);
                }else {
                    $this->sendMessage($this->getCurrentTaskUser(), 'Can\'t find tag ' . $this->getCurrentTaskTag());
                    $this->deleteCurrentTask();
                }
            }
        }
    }


    /**
     * Проверяет, от имеет ли человек устанавливать промо канал.
     *
     * @return bool
     */
    private function isAdmin(): bool
    {
        return in_array($this->from_id, self::ADMINS, true) ? true : false;
    }


    /**
     * Подготавливает task, временный метод.
     * Готовые таски в будущем должен присылать @DRProxyBot
     *
     * @param $channel
     * @param $tag
     *
     * @return string
     */
    private function prepareTask($channel, $tag): string
    {
        $task = [
            'command'   => 'promo',
            'channel'   => $channel,
            'from_id'   => $this->from_id,
            'tag'       => $tag
        ];
        return json_encode($task);
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

    private function isValidTask($item): bool
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

    /**
     * @return string
     */
    private function getCurrentTaskUser():string
    {
        return current($this->stack)['from_id'];
    }

    private function getCurrentTaskChannel()
    {
        return current($this->stack)['channel'];
    }

    private function getCurrentTaskTag()
    {
        return current($this->stack)['tag'];
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
