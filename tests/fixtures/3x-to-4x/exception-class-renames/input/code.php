<?php

use Elgg\BadRequestException;
use Elgg\EntityNotFoundException;

class MyHandler {
    public function handle(): void {
        try {
            throw new \RegistrationException('Bad registration');
        } catch (\DatabaseException $e) {
            error_log($e->getMessage());
        } catch (Elgg\BadRequestException $e) {
            error_log($e->getMessage());
        }

        if ($entity instanceof \Elgg\EntityNotFoundException) {
            return;
        }
    }
}
