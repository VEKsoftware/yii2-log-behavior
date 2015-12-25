<?php
namespace log\behaviors;

use yii\db\ActiveRecord;
use yii\base\Behavior;
use yii\db\AfterSaveEvent;

/**
 * Behavior class for logging all changes to a log table.
 * It requires a log model. By default it is owner class name + 'Log'.
 *
 * @prop array $logAttributes A list of all attributes to be saved in the log table
 * @prop string $logClass     Class name for the log model
 *
 *
 */
class Log extends Behavior
{
    /**
     * A list of all attributes to be saved in the log table
     */
    public $logAttributes;

    /**
     * Class name for the log model
     */
    public $logClass;

    /**
     * @inherit
     */
    public function events()
    {
        return [
            ActiveRecord::EVENT_AFTER_INSERT => 'logAfterSave',
            ActiveRecord::EVENT_AFTER_UPDATE => 'logAfterSave',
        ];
    }

    /**
     * @inherit
     */
    public function attach($owner)
    {
        if(is_null($this->logClass)) {
            $this->logClass = $owner->className()."Log";
        }
        if (!class_exists($this->logClass, false)) {
            throw new ErrorException('Model for logging "'.$this->logClass.'" ');
        }
        return parent::attach($owner);
    }

    /**
     * Saves a record to the log table
     *
     * @param \yii\db\AfterSaveEvent $event
     */
    public function getLogged($attributes)
    {
        if(is_string($attributes)) {
            $attributes[] = $attributes;
        }
        return $this->owner
            ->hasMany($this->logClass,['doc_id' => 'id'])
//            ->andFilterWhere()
        ;
    }

    /**
     * Saves a record to the log table
     *
     * @param \yii\db\AfterSaveEvent $event
     */
    public function logAfterSave($event)
    {
        if(! $event instanceof AfterSaveEvent) return;

        $attributes = $this->owner->getAttributes($logAttributes);
        $attributes['doc_id'] = $attributes['id'];
        unset($attributes['id']);
        $attributes['changedAttributes'] = array_keys($event->changedAttributes);

        $log = new $logClass($attributes);
        $log->save();
    }
}
