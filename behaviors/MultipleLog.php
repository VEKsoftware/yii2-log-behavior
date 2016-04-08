<?php

namespace log\behaviors;

use app\base\VekActiveRecord;
use Yii;
use yii\base\ErrorException;
use yii\base\Event;
use Yii\console\Application;
use yii\db\ActiveRecord;
use yii\db\AfterSaveEvent;
use yii\db\StaleObjectException;
use yii\helpers\ArrayHelper;

/**
 * Behavior class for logging all changes to a log table.
 * It requires a log model. By default it is owner class name + 'Log'.
 *
 * @property array $logAttributes          A list of all attributes to be saved in the log table
 * @property string $logClass               Class name for the log model
 * @property string $changedAttributesField Field in the table to store changed attributes list. Default: changed_attributes
 * @property string $changedByField         Field in the table to store the author of the changes (Yii::$app->user->id). Default: changed_by
 * @property string $timeField              Field where the time of last change is stored. Default: atime
 */
class MultipleLog extends Log
{
    protected static $_eventSwitched = false;

    /**
     * @inherit
     */
    public function events()
    {
        return parent::events() + [
            VekActiveRecord::EVENT_TO_SAVE_MULTIPLE => 'logToSaveMultiple',
            VekActiveRecord::EVENT_BEFORE_INSERT_MULTIPLE => 'logBeforeSaveMultiple',
            VekActiveRecord::EVENT_BEFORE_UPDATE_MULTIPLE => 'logBeforeSaveMultiple',
            VekActiveRecord::EVENT_AFTER_INSERT_MULTIPLE => 'logAfterSaveMultiple',
            VekActiveRecord::EVENT_AFTER_UPDATE_MULTIPLE => 'logAfterSaveMultiple',
        ];
    }

    /**
     * @inheritdoc
     *
     * @param ActiveRecord $owner
     *
     * @throws ErrorException
     */
    public function attach($owner)
    {
        if (!self::$_eventSwitched) {
            Event::on($owner->className(), VekActiveRecord::EVENT_TO_SAVE_MULTIPLE, [self::className(), 'logToSaveMultiple']);
            Event::on($owner->className(), VekActiveRecord::EVENT_SAVED_MULTIPLE, [self::className(), 'logSavedMultiple']);
        }
        parent::attach($owner);
    }

    /**
     * Check for versions for all records
     *
     * @param Event $event
     *
     * @return bool
     * @throws StaleObjectException
     */
    public static function logToSaveMultiple($event)
    {
        $senderClass = $event->sender;
        $models = $senderClass::getSaveMultiple(); // List of models to be saved;

        $updateModels = [];
        $primary_keys = [];
        $versionField = NULL;
        $tableFields = [];
        $types = [];
        foreach ($models as $model) {
            /** @var ActiveRecord|Log $model */
            // Игнорирование проверки версий
            if (($model->isNewRecord === false) || ($model->versionField)) {
                continue;
            }
            if (empty($versionField) || empty($primary_keys)) {
                $primary_keys = $model->primaryKey();
                $versionField = $model->versionField;
                $tableFields = array_merge($primary_keys, [$versionField]);
                $types = ArrayHelper::getColumn($model->getTableSchema()->columns, 'dbType');
            }
            $updateModels[] = $model;
            $updateStrs = [];
            foreach ($tableFields as $field) {
                if ($updateModels->$field === NULL) {
                    $updateStrs[] = 'NULL::' . $types[$field];
                } else {
                    $updateStrs[] = Yii::$app->db->quoteValue($updateModels->$field) . '::' . $types[$field];
                }
            }
            $updates[] = '(' . implode(', ', $updateStrs) . ')';
        }

        // Nothing to do
        if (count($updateModels) === 0) {
            return true;
        }

        $on_pk = [];
        foreach ($primary_keys as $pk) {
            $on_pk[] = '[[t.' . $pk . ']] = [[v.' . $pk . ']]';
        }

        /** @var array $updates */
        $sql = 'SELECT ' . implode(', ', $primary_keys) . ' FROM {{%' . $senderClass::tableName() . '}} AS t '
            . 'LEFT JOIN (VALUES ' . implode(', ', $updates) . ') AS v([[' . implode(']], [[', $tableFields) . ']]) '
            . 'ON ' . implode(' AND ', $on_pk)
            . 'WHERE [[' . $versionField . ']] != [[' . $versionField . ']]';

        // die($db->quoteSql($sql));
        $faultyCount = Yii::$app->db->createCommand(Yii::$app->db->quoteSql($sql))->execute();

        if ($faultyCount > 0) {
            throw new StaleObjectException('Some or all objects being updated are outdated.');
            /** PhpUnreachableStatementInspection */
//            $event->isValid = false;
//            return false;
        }
        return true;
    }

    /**
     * Sets the time of change.
     *
     */
    public function logBeforeSaveMultiple()
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

        // Set new version of the record
        // assuming checking for old version is done in [[logToSaveMultiple()]]
        if (isset($this->versionField)) {
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
    public function logAfterSaveMultiple($event)
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
        $attributes[$this->changedAttributesField] = '{' . implode(',', array_keys($this->_changed_attributes)) . '}';

        if ($this->changedByField) {
            if (Yii::$app instanceof Application) {
                $attributes[$this->changedByField] = null;
            } else {
                $attributes[$this->changedByField] = Yii::$app->user->isGuest ? null : Yii::$app->user->id;
            }
        }

        /** @var VekActiveRecord $logClass */
        $logClass = $this->logClass;
        /** @var ActiveRecord $log */

        /** @var $log */
        $log = new $logClass($attributes);

        $logClass::addSaveMultiple($log);

    }

    public static function logSavedMultiple($event)
    {
        $senderClass = $event->sender;
        $tmp_model = new $senderClass();

        $logClass = $tmp_model->logClass;
        unset($tmp_model);
        /** @var VekActiveRecord $logClass */
        return $logClass::saveMultiple();
    }

}
