<?php

namespace log\base;

use Yii;
use yii\base\ErrorException;
use yii\base\Event;
use yii\base\ModelEvent;
use yii\db\ActiveRecord;
use yii\db\AfterSaveEvent;
use yii\helpers\ArrayHelper;

/**
 * This is an extension of the \yii\db\ActiveRecord class which implements method saveMultiple()
 */
class MultipleActiveRecord extends ActiveRecord
{
    /**
     * @event Event an event that is triggered before [[saveMultiple()]] method starts to process all models.
     */
    const EVENT_TO_SAVE_MULTIPLE = 'toSaveMultiple';

    /**
     * @event Event an event that is triggered before [[saveMultiple()]] method starts to save all models to be inserted.
     */
    const EVENT_BEFORE_INSERT_MULTIPLE = 'beforeInsertMultiple';

    /**
     * @event Event an event that is triggered after [[saveMultiple()]] method finishes to save all models to be inserted.
     */
    const EVENT_AFTER_INSERT_MULTIPLE = 'afterInsertMultiple';

    /**
     * @event Event an event that is triggered before [[saveMultiple()]] method starts to save all models to be inserted.
     */
    const EVENT_BEFORE_UPDATE_MULTIPLE = 'beforeUpdateMultiple';

    /**
     * @event Event an event that is triggered after [[saveMultiple()]] method finishes to save all models to be inserted.
     */
    const EVENT_AFTER_UPDATE_MULTIPLE = 'afterUpdateMultiple';

    /**
     * @event Event an event that is triggered after [[saveMultiple()]] method finishes its work.
     */
    const EVENT_SAVED_MULTIPLE = 'savedMultiple';

    /**
     * @var MultipleActiveRecord[] All models to batch save are stored here
     */
    protected static $_models = [];

    /**
     * @var integer[] Saved models after call saveMultiple() was be stored here
     */
    protected static $_toInsertModels = [];

    /**
     * @var integer[] Updated models after call saveMultiple() was be stored here
     */
    protected static $_toUpdateModels = [];

    /**
     * This method add one or set of models into the queue of [[saveMultiple()]]
     * @param \log\base\MultipleActiveRecord|\log\base\MultipleActiveRecord[] $models models to save in a butch query
     */
    public static function addSaveMultiple($models)
    {
        if (is_array($models)) {
            static::$_models = array_merge(static::$_models, $models);
        } elseif ($models) {
            array_push(static::$_models, $models);
        }
    }

    /**
     * This method returns the list of models to be saved by [[saveMultiple()]] method
     * @return \log\base\MultipleActiveRecord[] models to save in a butch query
     */
    public static function getSaveMultiple()
    {
        return static::$_models;
    }

    /**
     * Clear the queue for [[saveMultiple()]]
     */
    public static function clearSaveMultiple()
    {
        static::$_models = [];
    }

    /**
     * This method saves a series of models in one batch query
     * @param \log\base\MultipleActiveRecord|\log\base\MultipleActiveRecord[] $models models to save in a butch query
     * @param boolean $runValidation whether to validate all models before save
     * @return bool
     * @throws ErrorException
     * @throws \Exception
     * @throws \yii\base\InvalidConfigException
     * @throws \yii\db\Exception
     */
    public static function saveMultiple($models = [], $runValidation = true)
    {
        if (count($models) > 0) {
            static::addSaveMultiple($models);
        }

        $attributes = [];
        $primary_keys = [];

        // Search for all attributes and checking all models
        foreach (static::$_models as $model) {
            /** @var MultipleActiveRecord $model */
            $attributes = array_merge($attributes, array_keys($model->attributes));

            if ((count($primary_keys) === 0) && count($model->primaryKey()) > 0) {
                $primary_keys = $model->primaryKey();
            }
        }

        $attributes = array_unique($attributes);
        $attributes_keyed = array_fill_keys($attributes, NULL);

        /** @var MultipleActiveRecord[] $inserts */
        $inserts = [];
        /** @var MultipleActiveRecord[] $updates */
        $updates = [];

        // Обнуляем переменные
        static::$_toInsertModels = [];
        static::$_toUpdateModels = [];

        $db = static::getDb();

        if (static::toSaveMultiple() === false) {
            return false;
        }

        foreach (static::$_models as $model) {
            $values = $model->attributes + $attributes_keyed;

            if ($runValidation && !$model->validate($attributes)) {
                return false;
            }

            if (!$model->beforeSaveMultiple($model->isNewRecord)) {
                return false;
            }

            if ($model->isNewRecord) {
                $inserts[] = array_diff_key($values, array_fill_keys($primary_keys, NULL));
                static::$_toInsertModels[] = $model;
            } else {
                // prepare a list of values for SQL UPDATE operation
                $val_strs = [];
                $types = ArrayHelper::getColumn($model->getTableSchema()->columns, 'dbType');
                foreach ($attributes as $attr) {
                    if (is_null($values[$attr])) {
                        $val_strs[] = 'NULL::' . $types[$attr];
                    } else {
                        $val_strs[] = $db->quoteValue($values[$attr]) . '::' . $types[$attr];
                    }
                }
                $updates[] = '(' . implode(', ', $val_strs) . ')';
                static::$_toUpdateModels[] = $model;
            }
        }

        if (!empty($inserts)) {
            $insertAttributes = array_diff($attributes, $primary_keys);

            $affectedRowsCount = (int)$db->createCommand()->batchInsert(static::tableName(), $insertAttributes, $inserts)->execute();
            $lastId = (int)$db->getLastInsertID($db->getTableSchema(static::tableName())->sequenceName);
            $firstID = $lastId - $affectedRowsCount + 1;
            $currentID = $firstID;

            // Добавляем идентификаторы
            foreach (static::$_toInsertModels as $model) {
                array_map(function ($key) use ($model, $currentID) {
                    $model->{$key} = $currentID;
                }, array_keys($model->getPrimaryKey(true)));
                $model->isNewRecord = false;
                $currentID++;
            }
        }

        if (!empty($updates)) {
            /*
            Here we do next construction
            update test as t set
                column_a = c.column_a
            from (values
                ('123', 1),
                ('345', 2)
            ) as c(column_b, column_a)
            where c.column_b = t.column_b;
            */
            $attr_set = null;
            $where_pk = null;
            foreach ($attributes as $attr) {
                /** @var MultipleActiveRecord $model */
                if (in_array($attr, $model->primaryKey())) {
                    continue;
                }
                // List of attributes for SET t.attribute = v.attribute
                $attr_set[] = '[[' . $attr . ']] = [[v.' . $attr . ']]';
            }

            // List of primary keys for WHERE clause
            foreach ($primary_keys as $pk) {
                $where_pk[] = '[[t.' . $pk . ']] = [[v.' . $pk . ']]';
            }

            $sql = 'UPDATE {{%' . static::tableName() . '}} as t '
                . 'SET ' . implode(', ', $attr_set) . ' '
                . 'FROM (VALUES ' . implode(', ', $updates) . ') as v([[' . implode(']], [[', $attributes) . ']]) '
                . 'WHERE ' . implode(' AND ', $where_pk);

            // die($db->quoteSql($sql));
            $db->createCommand($db->quoteSql($sql))->execute();
        }


        foreach (static::$_models as $model) {
            $values = $model->getDirtyAttributes();
            $changedAttributes = [];
            foreach ($values as $name => $value) {
                $oldAttribute = $model->getOldAttribute($name);
                $changedAttributes[$name] = isset($oldAttribute) ? $oldAttribute : null;
                $model->setOldAttribute($name, $value);
            }
            $model->afterSaveMultiple(false, $changedAttributes);
        }
        static::savedMultiple();
        return true;
    }

    /**
     * This static method is called at the end of work of [[saveMultiple()]] method and after calling [[afterSaveMultiple($insert)]] methods for each models saved.
     * The default implementation will trigger an [[EVENT_SAVED_MULTIPLE]]
     * When overriding this method, make sure you call the parent implementation so that
     * the event is triggered.
     */

    public static function toSaveMultiple()
    {
        $event = new ModelEvent(['sender' => static::className()]);
        Event::trigger(static::className(), self::EVENT_TO_SAVE_MULTIPLE, $event);
        return $event->isValid;
    }

    /**
     * This method is called at the beginning of inserting or updating all records using [[saveMultiple()]] method.
     * The default implementation will trigger an [[EVENT_BEFORE_INSERT_MULTIPLE]] event when `$insert` is true,
     * or an [[EVENT_BEFORE_UPDATE_MULTIPLE]] event if `$insert` is false.
     * When overriding this method, make sure you call the parent implementation like the following:
     *
     * ```php
     * public function beforeSaveMultiple($insert)
     * {
     *     if (parent::beforeSaveMultiple($insert)) {
     *         // ...custom code here...
     *         return true;
     *     } else {
     *         return false;
     *     }
     * }
     * ```
     *
     * @param boolean $insert whether this method called while inserting a record.
     * If false, it means the method is called while updating a record.
     * @return boolean whether the insertion or updating should continue.
     * If false, the insertion or updating will be cancelled.
     */
    public function beforeSaveMultiple($insert)
    {
        $event = new ModelEvent;
        $this->trigger($insert ? self::EVENT_BEFORE_INSERT_MULTIPLE : self::EVENT_BEFORE_UPDATE_MULTIPLE, $event);

        return $event->isValid;
    }

    /**
     * This method is called at the end of inserting or updating records using [[saveMultiple()]] method.
     * The default implementation will trigger an [[EVENT_AFTER_INSERT_MULTIPLE]] event when `$insert` is true,
     * or an [[EVENT_AFTER_UPDATE_MULTIPLE]] event if `$insert` is false. The event class used is [[AfterSaveEvent]].
     * When overriding this method, make sure you call the parent implementation so that
     * the event is triggered.
     * @param boolean $insert whether this method called while inserting a record.
     * If false, it means the method is called while updating a record.
     * @param array $changedAttributes The old values of attributes that had changed and were saved.
     * You can use this parameter to take action based on the changes made for example send an email
     * when the password had changed or implement audit trail that tracks all the changes.
     * `$changedAttributes` gives you the old attribute values while the active record (`$this`) has
     * already the new, updated values.
     */
    public function afterSaveMultiple($insert, $changedAttributes)
    {
        $this->trigger($insert ? self::EVENT_AFTER_INSERT_MULTIPLE : self::EVENT_AFTER_UPDATE_MULTIPLE, new AfterSaveEvent([
            'changedAttributes' => $changedAttributes
        ]));
    }

    /**
     * This static method is called at the end of work of [[saveMultiple()]] method and after calling [[afterSaveMultiple($insert)]] methods for each models saved.
     * The default implementation will trigger an [[EVENT_SAVED_MULTIPLE]]
     * When overriding this method, make sure you call the parent implementation so that
     * the event is triggered.
     */
    public static function savedMultiple()
    {
        $event = new ModelEvent(['sender' => static::className()]);
        Event::trigger(static::className(), self::EVENT_SAVED_MULTIPLE, $event);
        static::clearSaveMultiple();
    }

}
