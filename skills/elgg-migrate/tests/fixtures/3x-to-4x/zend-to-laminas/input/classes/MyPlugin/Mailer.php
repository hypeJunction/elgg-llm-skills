<?php
namespace MyPlugin;

use Zend\Mail\Message;
use Zend\Mail\Transport\Sendmail;

class Mailer {
    public function send(): void {
        $message = new Message();
        $transport = new Sendmail();
        $transport->send($message);
    }

    public function check($msg): bool {
        return $msg instanceof \Zend\Mail\Message;
    }
}
