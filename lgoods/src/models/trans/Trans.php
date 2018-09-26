<?php
namespace lgoods\models\trans;

use yii\db\ActiveRecord;
use yii\behaviors\TimestampBehavior;

class Trans extends ActiveRecord{

    CONST TRADE_ORDER = 1;

    CONST TPS_NOT_PAY = 0;

    CONST TPS_PAID = 1;



    public static function tableName(){
        return "{{%trans}}";
    }

    public function behaviors()
    {
        return [
            [
                'class' => TimestampBehavior::className(),
                'createdAtAttribute' => 'trs_created_at',
                'updatedAtAttribute' => 'trs_updated_at'
            ]
        ];
    }

}