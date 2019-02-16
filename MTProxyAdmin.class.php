<?php


class MTProxyAdmin extends \danog\MadelineProto\EventHandler
{

    const DRPROXYBOT = 1626995;
    const MTPROXYBOT = 571465504;

    private $hashes = [];
    private $need_stat = null;
    private $newproxy = null;
    private $setchannel = null;

    private $last_msg_id;

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
        \danog\MadelineProto\Logger::log(json_encode($update, JSON_PRETTY_PRINT));

        // Игнорировать исходящие сообщения
        if (isset($update['message']['out']) && $update['message']['out']) {
            return;
        }
        // Игнорируем если не можем определить от кого сообщение
        if (!isset($update['message']['from_id'], $update['message']['message'])) {
            return;
        }
        if ($update['message']['from_id'] === self::DRPROXYBOT) {
            if ($this->isCommand($update['message']['message'], '/newproxy')) {
                if (preg_match('/server\=(?<server>.*?)\&port\=(?<port>\d+)\&secret\=(?<secret>\w+)/', $update['message']['message'], $match)) {
                    $this->newproxy = $match;
                    $this->last_msg_id = $update['message']['id'];
                    $this->sendMessage(self::MTPROXYBOT, '/newproxy');
                } else {
                    $message = 'Необходима ссылка вида: tg://proxy?server=server.ru&port=443&secret=dd000000000000000000';
                    $this->sendMessage(self::DRPROXYBOT, $message, $update['message']['id']);
                }
            }
            if($this->isCommand($update['message']['message'], '/stat')){
                if(preg_match('/stat\s+(?<hash>\w+)/', $update['message']['message'], $match)){
                    $this->need_stat = $match['hash'];
                    $this->last_msg_id = $update['message']['id'];
                    $this->sendMessage(self::MTPROXYBOT, '/myproxies');
                }else{
                    $message = 'Необходим секретный tag прокси: /stat bf3756d89';
                    $this->sendMessage(self::DRPROXYBOT, $message, $update['message']['id']);
                }
            }
            if($this->isCommand($update['message']['message'], '/setchannel')){
                if(preg_match('/setchannel\s+(?<hash>\w+)\s+(?<channel>\@?\w+)/', $update['message']['message'], $match)){
                    $this->setchannel = $match;
                    $this->last_msg_id = $update['message']['id'];
                    $this->sendMessage(self::MTPROXYBOT, '/myproxies');
                }
            }
        }
        if ($update['message']['from_id'] === self::MTPROXYBOT) {
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

}