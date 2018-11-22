<?php
/**
 * Created by PhpStorm.
 * User: master
 * Date: 18-11-2
 * Time: 下午2:00
 */
namespace lgoods\models\sale;

use lgoods\models\sale\caculators\Discount;
use yii\db\ActiveRecord;
use yii\behaviors\TimestampBehavior;

class SaleRule extends ActiveRecord{

    const SR_TYPE_GOODS = 1;
    CONST SR_TYPE_SKU = 2;
    CONST SR_TYPE_CATEGORY = 3;

    CONST SR_CACU_TYPE_DISCOUNT = 1;

    const SR_STATUS_VALID = 1;

    CONST SR_STATUS_INVALID = 2;

    public function rules(){
        return [
            ['sr_status', 'default', 'value' => self::SR_STATUS_VALID],
            ['sr_status', 'in', 'range' => [self::SR_STATUS_VALID, self::SR_STATUS_INVALID]],

            ['sr_object_id', 'required'],

            ['sr_object_type', 'required'],

            ['sr_start_at', 'required'],

            ['sr_end_at', 'required'],

            ['sr_name', 'required'],

            ['sr_usage_intro', 'string'],

            ['sr_caculate_type', 'required'],

            ['sr_caculate_params', 'required']
        ];
    }



    public static function tableName(){
        return "{{%sale_rule}}";
    }


    public function behaviors()
    {
        return [
            [
                'class' => TimestampBehavior::className(),
                'createdAtAttribute' => 'sr_created_at',
                'updatedAtAttribute' => 'sr_updated_at'
            ]
        ];
    }

    public function discount($priceItems){
        $caculator = $this->instanceCaculator($this->sr_caculate_type);
        $data = array_merge($priceItems, $this->toArray());
        if(!$caculator->check($data)){
            Yii::error($data);
            throw new \Exception("计算销售规则失败");
        }
        return $caculator->caculate($data);
    }

    public function instanceCaculator($type){
        switch ($type){
            case SaleRule::SR_CACU_TYPE_DISCOUNT:
                $caculator = new Discount();
                break;
            default:
                throw new \Exception("制订的计算方式不存在");
        }
        return $caculator;
    }



}