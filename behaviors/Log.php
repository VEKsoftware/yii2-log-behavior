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
     * ```php
     * 'logAttributes' => [
     *    'id', 'name', 'field1', // attributes from base model
     *    'static_field' => 'value', // static value to save in log table.
     *                               // If static_field exists in base table it will be overwritten by value
     *    'synamic_field' => function($owner) { // dynamic field will be computed before saving in log table.
     *                                          // If base table containes that attribute, the computed value will be used
     *          return md5($owner->id);
     *    },
     * ],
     * ```
     */
    public $logAttributes;

    /**
     * Class name for the log model.
     */
    public $logClass;

    /**
     * Field to save link to base model from the logged model
     * Default: 'doc_id'.
     */
    public $docId = 'doc_id';

    /**
     * Field to store list of attributes changed in the current save of the base table
     * Default: 'changed_attributes'.
     */
    public $changedAttributesField = 'changed_attributes';

    /**
     * Field for version lock data. It is used to forbid save if other process had changed the record before.
     * This dublicated the standard optimistic lock mechanism, but differs by a random chioce of the version field.
     * Such a choice prvents predictibility of the version and possible hacker attacs.
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
    protected $_to_save_attributes = [];
    
    protected $_beforeLogAttributes = [];
    protected $_closureLogAttributes = [];

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
        $logAttributes = $this->logAttributes;

        $this->_to_save_log = false;
        foreach($logAttributes as $key => $val) {
            if(is_int($key)) {
                // Значения - это имена атрибутов
                $aName = $val;
                $aValue = $this->owner->getAttribute($aName);
            } elseif($val instanceof \Closure) {
                // Ключ - имя атрибута, значение - вычисляемое
                $aName = $key;
                $aValue = call_user_func($val);
            } else {
                $aName = $key;
                $aValue = $val;
            }

            if($this->owner->hasAttribute($aName)) {
                if($aName === $this->timeField) {
                    continue;
                } elseif($this->owner->getOldAttribute($aName) != $aValue) {
                    $this->_to_save_attributes[$aName] = $aValue;
                    $this->_to_save_log = true;
                    $this->_changed_attributes[] = $aName;
                } else {
                    $this->_to_save_attributes[$aName] = $aValue;
                }
            } else {
                $this->_to_save_attributes[$aName] = $aValue;
            }
        }

        if ($this->_to_save_log ) {
            $time = static::returnTimeStamp();
            $this->owner->{$this->timeField} = $time;
            $this->_to_save_attributes[$this->timeField] = $time;
        } else {
            return true;
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
        $this->_to_save_attributes[$this->versionField] = $this->owner->{$this->versionField};
        return true;
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

        $this->_to_save_attributes[$this->docId] = $this->owner->id;
        unset($this->_to_save_attributes['id']);
        $this->_to_save_attributes[$this->changedAttributesField] = '{'.implode(',', array_values($this->_changed_attributes)).'}';
        
        $logClass = $this->logClass;
        /** @var ActiveRecord $log */
        $log = new $logClass();
        $log->setAttributes( array_intersect_key( $this->_to_save_attributes, $log->getAttributes() ) );
        
        if (!$log->save()) {
            throw new ErrorException(print_r($log->errors, true));
        }
    }

    /**
     * получить текущую отметку времени в текстовом формате
     */
    public static function returnTimeStamp()
    {
        $t = microtime(true);
        $micro = sprintf("%06d",($t - floor($t)) * 1000000);
        
        $date = new \DateTime( date('Y-m-d H:i:s.'.$micro, $t) );
        return $date->format('Y-m-d H:i:s.uP');
    }

}
