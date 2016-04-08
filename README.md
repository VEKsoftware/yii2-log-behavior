The Log behavior for models
===========================
This behavior provides standartized loggin functionality for models

Installation
------------

The preferred way to install this extension is through [composer](http://getcomposer.org/download/).

Either run

```
php composer.phar require --prefer-dist veksoftware/yii2-log-behavior "*"
```

or add

```
"veksoftware/yii2-log-behavior": "*"
```

to the require section of your `composer.json` file.


Usage
-----

There are two concepts of using of this extension. First one is just simple logging of normal ActiveRecord inherited models, while another one is the extended model MultipleActiveRecord with multiple saving capabilities.

### ActiveRecord logging

You need to create two DB tables (and two models respectively), for example ``table`` and ``table_log``. First one is the base table, while another one is the logging table. The base table optionally may conatin ``version`` field of type ``BIGINT`` (``int8``) (the name can be specified througth the config) which works for optimistic lock functianality, i.e. to prevent updating the record by outdated data in the multisession process. Also there must be a field where the time of setting current state is stored. By default it is ``atime``.
In the log table you need provide the following field:

1. ``id``
2. ``doc_id`` foreign linked to the id of ``table``;
2. ``changed_attributes`` to store the fields differing from the previos state;
3. `atime` must exist;
4. ``changed_by`` the user ID who is responsible for change of the state;
5. fields to log

In the base model ``Table`` you need to attach the Log behavior and set it up.
```php
   public function behaviors()
    {
        return [
            'log' => [
                'class' => \log\bahaviors\Log::className(),
                'logClass' => TableLog::className(), // class for Log model
                'timeField' => 'atime',
                'changedAttributesField' => 'changed_attributes',
                'changedByField' => 'changed_by',
                'versionField' => 'version',
                'logAttributes' => [ // fields to log
                    'id',
                    'category_id',
                    'owner_id',
                    'atime',
                    'status_id',
                    'model_id',
                    'document',
                ],
            ],
        ];
    }
```

If you use version control (versionField is not empty) you also have to add this field into your view:
```php
<?= $form->field($model, 'version')->hiddenInput()->label(false) ?>
```
Validators required no special changes since they are updated automatically by the log behavior.

That's all. All time you save your model the log behavior will be called and insert a log row into the appropriate table. If updating record is impossible due to outdated data ``yii\db\StaleObjectException`` will raise.

### MultipleActiveRecord

To save an array of models in one query you need to use ``log\base\MultipleActiveRecrod`` class as a parent of your model. Than you can use ``saveMultiple()`` method. Do it in the following way.
```php
<?php

namespace app\models;

use log\base\MultipleActiveRecord;

class Table extends MultipleActiveRecord
{
    /**
     * The method is called before saving starts.
     * Right here you can put some checks of all batch of models
     */
    public static function toSaveMultiple()
    {
      // false cancels saving
      return parent::toSaveMultiple();
    }

    /**
     * The method is called for each instance of the models.
     * Right here you can make model related checks and prepare data for saving.
     */
    public function beforeSaveMultiple($insert)
    {
        // Do whatever you want before saving each model instance
        // false cancels saving all tha batch
        return parent::beforeSaveMultiple($insert);
    }    

    /**
     * The method is called for each instance after saving the main batch of models by saveMultiple.
     * Right here you can queue saving of the related models
     */
    public function afterSaveMultiple($insert, $changedAttributes)
    {
        // Related tables saving trough a queue
        TableProps::addSaveMultiple($prop);
        
        parent::afterSaveMultiple($insert, $changedAttributes);
    }    

    /**
     * The method is called in the final stage of saveMultiple.
     * Right here you can finish saving all related records queued above.
     */
    public static function savedMultiple()
    {
        $res = TableProps::saveMultiple();
        if (!$res) {
            throw new ErrorException('Error occurred on save Item properties.');
        }
        parent::savedMultiple();
    }
}
```

So, you can add models for saving into the queue by ``Table::addSaveMultiple($models)`` and save them by ``Table::saveMultiple()``.

### Loggin data in saveMultiple()

As you want to use logging in ``saveMultiple()`` operations, you just need to use ``MultipleLog behavior`` instead of ``Log``. All settings remain the same.
