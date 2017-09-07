<?php

namespace Apps\CronManager\Php\Entities;

use Apps\Webiny\Php\Lib\Api\ApiContainer;
use Apps\Webiny\Php\Lib\WebinyTrait;
use Apps\Webiny\Php\Lib\Entity\AbstractEntity;
use Webiny\Component\Entity\Attribute\Validation\ValidationException;

/**
 * Class Jobs
 *
 * @property string $id
 * @property string $name
 * @property string $mask
 *
 * @package Apps\Webiny\Php\Entities
 *
 */
class JobFrequency extends AbstractEntity
{
    use WebinyTrait;

    protected static $entityCollection = 'CronManagerJobFrequency';
    protected static $entityMask = '{name}';

    public function __construct()
    {
        parent::__construct();

        $maskValidator = function ($mask) {
            $message = $this->getFrequency($mask);
            if (!$message) {
                throw new ValidationException('Invalid cron job pattern');
            }
        };

        $this->attr('name')->char()->setValidators('required')->setToArrayDefault();
        $this->attr('mask')
             ->char()
             ->setValidators(['required', $maskValidator, 'unique'])
             ->setToArrayDefault()
             ->setValidationMessages(['unique' => 'A frequency with this mask already exists!']);
    }

    protected function entityApi(ApiContainer $api)
    {
        parent::entityApi($api);

        /**
         * @api.name Validate
         * @api.url /validate
         * @api.body.mask string Cron job mask that will be validated
         */
        $api->post('validate', function () {
            $data = $this->wRequest()->getRequestData();
            $mask = trim($data['mask']);

            $frequency = $this->getFrequency($mask);
            $status = true;
            if (!$frequency) {
                $status = false;
            } else {
                if (static::findOne(['mask' => $mask])) {
                    $status = false;
                    $frequency = 'A frequency with this mask already exists!';
                }
            }

            return [
                'status'    => $status,
                'frequency' => $frequency
            ];
        })->setBodyValidators(['mask' => 'required']);
    }


    private function getFrequency($mask)
    {
        try {
            $cronRunner = \Cron\CronExpression::factory($mask);

            $prev = $cronRunner->getNextRunDate('now', 0, true)->format('Y-m-d H:i:00');
            $next = $cronRunner->getNextRunDate('now', 1, true)->format('Y-m-d H:i:00');

            return '(' . $prev . ', ' . $next . ')';
        } catch (\Exception $e) {
            return '';
        }
    }
}