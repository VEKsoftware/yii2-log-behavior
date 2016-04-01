<?php

namespace log\behaviors;

use Yii;
use yii\base\Behavior;
use yii\base\ErrorException;
use yii\base\Event;
use Yii\console\Application;
use yii\db\ActiveQueryInterface;
use yii\db\ActiveRecord;
use yii\db\AfterSaveEvent;
use yii\db\StaleObjectException;
use yii\validators\Validator;

/**
 * Behavior class for logging all changes to a log table.
 * It requires a log model. By default it is owner class name + 'Log'.
 *
 * @property array  $logAttributes          A list of all attributes to be saved in the log table
 * @property string $logClass               Class name for the log model
 * @property string $changedAttributesField Field in the table to store changed attributes list. Default: changed_attributes
 * @property string $changedByField         Field in the table to store the author of the changes (Yii::$app->user->id). Default: changed_by
 * @property string $timeField              Field where the time of last change is stored. Default: atime
 */
class Log extends Behavior
{
    /**
     * @var ActiveRecord the owner of this behavior
     */
    public $owner;

    /**
     * array A list of all attributes to be saved in the log table.
     */
    public $logAttributes;

    /**
     * Class name for the log model.
     */
    public $logClass;

    /**
     * Field of the table to store id of the user who changed the record
     * Default: 'changed_by'.
     */
    public $changedByField = 'changed_by';

    /**
     * Value for 'changedByField', needed in console mode of Yii2 application.
     */
    public $changedByValue;

    /**
     * Field to store changed attributes
     * Default: 'changed_attributes'.
     */
    public $changedAttributesField = 'changed_attributes';

    /**
     * Field for version lock data. It is used to forbid save if other process had changed the record before.
     * Default: 'version'.
     */
    public $versionField;

    /**
     * Field where the time of last change is stored
     * Default: 'atime'.
     */
    public $timeField = 'atime';

    protected $_to_save_log = false;
    protected $_changed_attributes = [];

    /**
     * @inherit
     */
    public function events()
    {
        return [
            ActiveRecord::EVENT_INIT          => 'logInit',
            ActiveRecord::EVENT_BEFORE_INSERT => 'logBeforeSave',
            ActiveRecord::EVENT_BEFORE_UPDATE => 'logBeforeSave',
            ActiveRecord::EVENT_AFTER_INSERT  => 'logAfterSave',
            ActiveRecord::EVENT_AFTER_UPDATE  => 'logAfterSave',
        ];
    }

    /**
     * @inherit
     *
     * @param ActiveRecord $owner
     *
     * @throws ErrorException
     */
    public function attach($owner)
    {
        if (is_null($this->logClass)) {
            $this->logClass = $owner->className().'Log';
        }
        if (!class_exists($this->logClass, false)) {
            throw new ErrorException('Model for logging "'.$this->logClass.'" ');
        }

        if (isset($this->versionField) && !$owner->isNewRecord) {
            // die('new: '.$owner->isNewRecord);
            $owner->validators[] = Validator::createValidator('required', $owner, $this->versionField, []);
            $owner->validators[] = Validator::createValidator('string', $owner, $this->versionField, ['max' => '50']);
            $owner->validators[] = Validator::createValidator('match', $owner, $this->versionField, ['pattern' => '/^[0-9]+$/']);
        }
        parent::attach($owner);
    }

    /**
     * Saves a record to the log table.
     *
     * @param null $attributes
     *
     * @return ActiveQueryInterface
     *
     * @internal param AfterSaveEvent $event
     */
    public function getLogged($attributes = null)
    {
        $attr = [];
        if (is_string($attributes)) {
            $attr[] = $attributes;
        } elseif (is_array($attributes)) {
            $attr = $attributes;
        }

        if (count($attr) > 0) {
            $array = '{'.implode(',', $attr).'}';
        } else {
            $array = null;
        }

        return $this->owner
            ->hasMany($this->logClass, ['doc_id' => 'id'])
            ->andFilterWhere(['&&', $this->changedAttributesField, $array]);
    }

    /**
     * Sets new version for the current record
     */
    public function setNewVersion()
    {
        $difference = '9223372036854775806';
        $rand_percent = bcdiv(mt_rand(), mt_getrandmax(), 12);
        $version = bcmul($difference, $rand_percent, 0);
        $this->owner->setAttribute($this->versionField, $version);
    }

    /**
     * Sets version on init of new ActiveRecord
     *
     * @param Event $event
     */
    public function logInit($event)
    {
        $this->setNewVersion();
    }

    /**
     * Sets the time of change.
     *
     * @param Event $event
     *
     * @throws StaleObjectException
     */
    public function logBeforeSave($event)
    {
        // Computing attributes to save (diff)
        $attributes = array_diff($this->logAttributes, [$this->timeField]);
        $new = $this->owner->getDirtyAttributes($attributes);
        $old = $this->owner->oldAttributes;
        $diff = array_diff_assoc($new, $old);
        $this->_changed_attributes = $diff;
        if (count($diff) > 0) {
            $this->owner->{$this->timeField} = date('Y-m-d H:i:sP');
            $this->_to_save_log = true;
        } else {
            $this->_to_save_log = false;
        }

        // Check current version of the record before update
        if (isset($this->versionField)) {
            if ($event->name === 'beforeUpdate') {
                $row = $this->owner->find()->where($this->owner->getPrimaryKey(true))->select($this->versionField)->asArray()->one();
                if (isset($row[$this->versionField]) && (string) $row[$this->versionField] !== (string) $this->owner->getAttribute($this->versionField)) {
                    throw new StaleObjectException('The object being updated is outdated.');
                }
            }
            $this->setNewVersion();
        }
    }

    /**
     * Saves a record to the log table.
     *
     * @param \yii\db\AfterSaveEvent $event
     *
     * @throws ErrorException
     */
    public function logAfterSave($event)
    {
        if (!$event instanceof AfterSaveEvent) {
            return;
        }
        if (is_null($event->changedAttributes)) {
            return;
        }
        if (!$this->_to_save_log) {
            return;
        }

        $attributes = $this->owner->getAttributes($this->logAttributes);
        $attributes['doc_id'] = $attributes['id'];
        unset($attributes['id']);
        $attributes[$this->changedAttributesField] = '{'.implode(',', array_keys($this->_changed_attributes)).'}';

        if ($this->changedByField) {
            if (Yii::$app instanceof Application) {
                $attributes[$this->changedByField] = null;
            } else {
                $attributes[$this->changedByField] = Yii::$app->user->isGuest ? null : Yii::$app->user->id;
            }
        }

        $logClass = $this->logClass;
        /** @var ActiveRecord $log */
        $log = new $logClass($attributes);

        if (!$log->save()) {
            throw new ErrorException(print_r($log->errors, true));
        }
    }
}
