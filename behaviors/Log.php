<?php
namespace log\behaviors;

use Yii;
use yii\db\ActiveRecord;
use yii\base\Behavior;
use yii\db\AfterSaveEvent;

/**
 * Behavior class for logging all changes to a log table.
 * It requires a log model. By default it is owner class name + 'Log'.
 *
 * @prop array  $logAttributes          A list of all attributes to be saved in the log table
 * @prop string $logClass               Class name for the log model
 * @prop string $changedAttributesField Field in the table to store changed attributes list. Default: changed_attributes
 * @prop string $changedByField         Field in the table to store the author of the changes (Yii::$app->user->id). Default: changed_by
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
     * Field of the table to store id of the user who changed the record
     * Default: 'changed_by'
     */
    public $changedByField = 'changed_by';

    /**
     * Field to store changed attributes
     * Default: 'changed_attributes'
     */
    public $changedAttributesField = 'changed_attributes';

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

        $attributes = $this->owner->getAttributes($this->logAttributes);
        $attributes['doc_id'] = $attributes['id'];
        unset($attributes['id']);
        $attributes[$this->changedAttributesField] = array_keys($event->changedAttributes);
        $attributes[$this->changedByField] = Yii::$app->user->id;

        $logClass = $this->logClass;
        $log = new $logClass($attributes);
/*
        echo "<pre>";
        var_dump($attributes);
        echo "</pre>";
        die();
*/
        if(! $log->save()) {
            throw new ErrorException(print_r($log->errors,true));
        }
    }
}
