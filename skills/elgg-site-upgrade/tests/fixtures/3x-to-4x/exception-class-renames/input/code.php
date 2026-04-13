<?php

use Elgg\BadRequestException;
use Elgg\EntityNotFoundException;
use Elgg\Loggable;
use Elgg\TimeUsing;
use Elgg\Cacheable;
use Elgg\Di\ServiceFacade;

class MyHandler {
    use Loggable;
    use TimeUsing;

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

class MyService {
    use ServiceFacade;
    use Cacheable;
}
