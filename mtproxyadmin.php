<?php
if (!file_exists('madeline.php')) {
    copy('https://phar.madelineproto.xyz/madeline.php', 'madeline.php');
}
require_once 'madeline.php';
require_once 'MTProxyAdmin.class.php';

$settings = [
    'app_info'=> [
        'api_id'=>35501,
        'api_hash'=>'ddc61e0d0ca69324b3f0672f4fbb5d37'
    ],
    'secret_chats' => [
        'accept_chats' => false
    ],
    'updates' => [
        'handle_old_updates' => false
    ],
    'logger'=> [
        'logger' => 2,
    ]
];

$MadelineProto = new \danog\MadelineProto\API('mtproxyadmin.madeline', $settings);

$me = $MadelineProto->get_self();

\danog\MadelineProto\Logger::log($me);

$MadelineProto->start();

$MadelineProto->setEventHandler('\MTProxyAdmin');
echo 'LIVE'.PHP_EOL;

while (1) {
    try {
        $MadelineProto->loop();
    } catch (danog\MadelineProto\TL\Exception $e) {
        fprintf(STDERR, ' danog\MadelineProto\TL\Exception: ' . $e->getMessage());
    } catch (\Exception $e) {
        fprintf(STDERR, $e->getMessage());
    }
}




