<?php
namespace lgoods\models\order;

use lbase\staticdata\ConstMap;
use lfile\models\ar\File;
use lfile\models\FileModel;
use lgoods\helpers\PriceHelper;
use lgoods\models\coupon\Coupon;
use lgoods\models\coupon\CouponModel;
use lgoods\models\sale\SaleModel;
use lgoods\models\trans\Trans;
use Yii;
use lgoods\models\goods\GoodsModel;
use yii\base\Model;
use yii\db\Query;
use yii\helpers\ArrayHelper;
use lgoods\models\order\AfterPayedEvent;

class OrderModel extends Model{
    public static function getLevelFields($level){
        $map = [
            'all' => [
                'og_discount_info' => null,
                'od_discount_items' => null,
            ],
            'list' => [

            ]
        ];
        return $map[$level];
    }

    public static function formatOrders($orders, $params = []){
        foreach($orders as &$order){
            $order = static::formatOneOrder($order, $params);
        }
        return $orders;
    }
    public static function formatOneOrder($data, $params = []){
        $fields = static::getLevelFields(ArrayHelper::getValue($params, 'fields_level', 'all'));

        if(!empty($data['od_discount_items'])){
            $data['od_discount_items'] = json_decode($data['od_discount_items'], true);
        }else{
            $data['od_discount_items'] = [];
        }
        if(!empty($data['od_discount_des'])){
            $data['od_discount_des'] = json_decode($data['od_discount_des'], true);
        }else{
            $data['od_discount_des'] = [];
        }
        $data['od_price_str'] = PriceHelper::format($data['od_price']);
        $data['od_discount_str'] = PriceHelper::format($data['od_discount']);

        if(!empty($data['order_goods_list'])){
            foreach($data['order_goods_list'] as &$ogItem){
                $ogItem['g_m_img_url'] = FileModel::buildFileUrlStatic(FileModel::parseQueryId($ogItem['g_m_img_id']));
                if(isset($ogItem['og_discount_items'])){
                    $ogItem['og_discount_items'] = json_decode($ogItem['og_discount_items'], true);
                }else{
                    $ogItem['og_discount_items'] = [];
                }
                if(isset($ogItem['og_discount_des'])){
                    $ogItem['og_discount_des'] = json_decode($ogItem['og_discount_des'], true);
                }else{
                    $ogItem['og_discount_des'] = [];
                }
            }
        }
        return $data;
    }

    public function ensureOrderCanPay($order){
        // todo
        return true;
    }


    public static function handleReceivePayedEvent($event){
        $trans = $event->sender;
        $payOrder = $event->payOrder;
        $order = static::findOrder()
                        ->andWhere(['=', 'od_id', $trans->trs_target_id])
                        ->one();
        ;
        if(Order::PS_PAID == $order['od_pay_status']){
            return true;
        }
        $order->od_pay_status = Order::PS_PAID;
        $order->od_paid_at = $trans->trs_pay_at;
        $order->od_pay_type = $trans->trs_pay_type;
        $order->od_pay_num = $trans->trs_pay_num;
        $order->od_trs_num = $trans->trs_num;
        if(false == $order->update(false)){
            throw new \Exception("订单修改失败");
        }
        $order->trigger(Order::EVENT_AFTER_PAID);
    }



    public static function ensureCanRefund($orderData){
        return [true, ''];
    }
    public static function findOrder(){
        return Order::find();
    }

    public static function findOrderFull($params = []){
        $fields = static::getLevelFields(ArrayHelper::getValue($params, 'fields_level', 'all'));
        $oTable = Order::tableName();

        $query = Order::find()
                      ->with([
                          'order_goods_list' => function(Query $query) use($fields){
                              $joinDiscount = array_key_exists("og_discount_info", $fields);
                              $select = [
                                 "og_od_id",
                                 "og_g_id",
                                 "og_name",
                                  "og_total_num",
                                  "og_single_price",
                                  "og_total_price",
                                  "og_g_sid",
                                  "og_g_stype",
                                  "og_sku_id",
                                  "og_sku_index",
                                  "og_id",
                                 "oe.g_m_img_id",
                             ];
                             if($joinDiscount){
                                 $select[] = "og_discount_items";
                                 $select[] = "og_discount_des";
                             }
                             $query->select($select);
                          }
                      ]);
        $query->from([
            'o' => $oTable,
        ]);
        $tTable = Trans::tableName();
        $odTable = OrderDiscount::tableName();
        $select = [
            "o.od_id",
            "o.od_num",
            "o.od_pay_status",
            "o.od_created_at",
            "o.od_price",
            'o.od_discount',
            "t.trs_id",
            "o.od_pay_type",
            "t.trs_pay_num",
            "t.trs_num",
            "t.trs_target_id",
            "t.trs_type",
            "od.od_discount_des"
        ];
        $query->leftJoin(['t' => $tTable], "t.trs_type = :p1 and t.trs_target_id = o.od_id", [":p1" => Trans::TRADE_ORDER]);
        $query->leftJoin(['od' => $odTable], "od.od_id = o.od_id");
        if(array_key_exists('od_discount_items', $fields)){
            $select[] = "od.od_discount_items";
        }
        $query->select($select);
        return $query;
    }

    public function createOrderFromSkus($orderData){
        $orderData = ArrayHelper::index($orderData['order_goods_list'], 'og_sku_id');
        $skuIds = array_keys($orderData);
        $skus = GoodsModel::findValidSku()
                          ->andWhere(['in', 'sku_id', $skuIds])
//                          ->indexBy('sku_id')
                          ->asArray()
                          ->all()
                          ;
        if(count($skus) != count($skuIds)){
            throw new \Exception("选定的商品存在遗漏");
        }
        $totalPrice = 0;
        $totalDiscount = 0;
        $skuIds = [];
        $gids = [];
        foreach($skus as $index => $sku){
            $skuIds[] = $sku['sku_id'];
            $gids[] = $sku['sku_g_id'];
        }
        $discountItems = SaleModel::fetchGoodsRules([
            'g_id' => $skuIds,
            'sku_id' => $gids
        ]);
        $ogListData = [];
        $couponsOgListParams = [];
        foreach($skus as $index => $sku){
            $buyParams = [
                'buy_num' => $orderData[$sku['sku_id']]['og_total_num']
            ];
            $buyParams['discount_items'] = $discountItems;
            $priceItems = GoodsModel::caculatePrice($sku, $buyParams);
            if($priceItems['has_error']){
                throw new \Exception($priceItems['error_des']);
            }
            $totalPrice += $priceItems['og_total_price'];
            $totalDiscount += $priceItems['og_total_discount'];

            $ogData = [
                'og_total_num' => $priceItems['og_total_num'],
                'og_single_price' => $priceItems['og_single_price'],
                'og_total_price' => $priceItems['og_total_price'],
                'og_name' => $sku['g_name'],
                'og_g_id' => $sku['sku_g_id'],
                'og_g_sid' => $sku['g_sid'],
                'og_g_stype' => $sku['g_stype'],
                'og_sku_id' => $sku['sku_id'],
                'og_sku_index' => $sku['sku_index'],
                'og_discount_items' => json_encode($priceItems['discount_items']),
                'og_discount_des' => json_encode($priceItems['discount_items_des']),
                'og_created_at' => time(),
                'og_updated_at' => time(),
            ];
            $couponsOgListParams[] = [
                'og_total_num' => $priceItems['og_total_num'],
                'og_single_price' => $priceItems['og_single_price'],
                'og_total_price' => $priceItems['og_total_price'],
                'og_discount_items' => $priceItems['discount_items'],
                'og_g_id' => $sku['sku_g_id'],
                'og_sku_id' => $sku['sku_id'],
            ];
            ksort($ogData);
            $ogListData[] = $ogData;
        }
        $orderSaleRules = SaleModel::fetchOrderRules([
            'total_price' => $totalPrice
        ]);
        $buyParams = [
            'discount_items' => $orderSaleRules,
            'og_list' => $couponsOgListParams,
            'buy_uid' => 1// todo
        ];
        $priceItems = static::caculatePrice([
            'total_price' => $totalPrice,
            'total_discount' => $totalDiscount,
        ], $buyParams);
        if($priceItems['has_error']){
            throw new \Exception($priceItems['error_des']);
        }

        $order = new Order();
        $order->od_pid = 0;
        $order->od_belong_uid = 0;
        $order->od_price = $priceItems['total_price'];
        $order->od_discount = $priceItems['total_discount'];
        $order->od_pay_status = Order::PS_NOT_PAY;
        $order->od_paid_at = 0;
        $order->od_title = static::buildOdTitleFromGoods($ogListData);
        $order->od_num = static::buildOrderNumber();
        $order->insert(false);
        foreach($ogListData as $i => $ogData){
            $ogListData[$i]['og_od_id'] = $order->od_id;
        }
        static::batchInsertOgData($ogListData);
        static::batchInsertODiscountData([
            [
                'od_id' => $order->od_id,
                'od_discount_items' => json_encode($priceItems['discount_items']),
                'od_discount_des' => json_encode($priceItems['discount_items_des'])
            ]
        ]);
        return $order;

    }

    /**
     * @param $ogList
     * - ci_sku_id required,integer
     * - ci_g_id required,integer
     * - ci_amount required,integer
     * - buy_uid required,integer
     * - use_coupons required,array#use_coupon_param
     * @param array $buyParams
     * @return array
     * @throws \Exceptionadf
     */
    public static function checkOrderFromOgList($ogList, $buyParams = []){
        $skuIds = [];
        $gids = [];
        foreach($ogList as $item){
            $skuIds[] = $item['ci_sku_id'];
            $gids[] = $item['ci_g_id'];
        }
        $discountRules = SaleModel::fetchGoodsRules([
            'g_id' => $skuIds,
            'sku_id' => $gids
        ]);
        $ogPriceItems = static::caculateOgListPrice($ogList, ['discount_items' => $discountRules]);
        $orderSaleRules = SaleModel::fetchOrderRules([
            'total_price' => $ogPriceItems['total_price'],
        ]);
        $buyParams['discount_items'] = $orderSaleRules;
        $buyParams['og_list'] = $ogPriceItems['og_list'];
        $priceItems = static::caculatePrice([
            'total_price' => $ogPriceItems['total_price'],
            'total_discount' => $ogPriceItems['total_discount'],
        ], $buyParams);

        if($priceItems['has_error']){
            throw new \Exception($priceItems['error_des']);
        }
        return $priceItems;
    }
    public static function caculateOgListPrice($ogList, $buyParams){
        $totalPrice = 0;
        $totalDiscount = 0;
        if(!empty($buyParams['discount_items'])){
            $goodsParams['discount_items'] = $buyParams['discount_items'];
        }else{
            $goodsParams = [];
        }
        $couponsOgListParams = [];
        foreach($ogList as &$item){
            $goodsParams['buy_num'] = $item['ci_amount'];
            $priceItems = GoodsModel::caculatePrice($item, $goodsParams);
            if($priceItems['has_error']){
                throw new \Exception($priceItems['error_des']);
            }
            $totalPrice += $priceItems['og_total_price'];
            $totalDiscount += $priceItems['og_total_discount'];
            $couponsOgListParams[] = [
                'og_total_num' => $priceItems['og_total_num'],
                'og_single_price' => $priceItems['og_single_price'],
                'og_total_price' => $priceItems['og_total_price'],
                'og_discount_items' => $priceItems['discount_items'],
                'og_g_id' => $item['ci_g_id'],
                'og_sku_id' => $item['ci_sku_id'],
            ];
        }
        return [
            'total_price' => $totalPrice,
            'total_discount' => $totalDiscount,
            'og_list' => $couponsOgListParams,
        ];
    }
    public static function caculateDiscountPrice($order, $buyParams){
        $priceItems = [
            'has_error' => 0,
            'error_des' => '',
            'total_price' => 0,
            'total_discount' => 0,
            'discount_items' => [],
            'discount_items_des' => []
        ];
        $defualtBuyParams = [
            'discount_items' => [],
            'valid_coupons' => []
        ];
        $buyParams = array_merge($defualtBuyParams, $buyParams);
        $priceItems['total_price'] = $order['total_price'];
        $priceItems['total_discount'] = $order['total_discount'];
        foreach($buyParams['discount_items'] as $saleRule){
            if(!SaleModel::checkAllow($order, $saleRule)){continue;}
            $discount = $saleRule->discount($priceItems);
            $discountParams = array_merge($saleRule->toArray(), [
                'discount' => $discount,
                'total_price' => $priceItems['total_price'],
                'total_discount' => $priceItems['total_price'],
            ]);
            $priceItems['discount_items'][] = $discountParams;
            $priceItems['discount_items_des'][] = static::buildDiscountItemDes($discountParams);
            $priceItems['total_price'] -= $discount;
            $priceItems['total_discount'] += $discount;
        }
        return $priceItems;
    }
    public static function caculateCouponPrice($order, $buyParams = []){
        $priceItems = [
            'total_price' => $order['total_price'],
            'total_discount'  => $order['total_discount'],
            'use_coupons' => $buyParams['use_coupons'],
            'valid_coupons' => [],
            'use_coupons_des' => [],
        ];
        $coupons = CouponModel::getUserValidCoupons([
            'buy_uid' => $buyParams['buy_uid'],
            'og_list' => $buyParams['og_list'],
            'total_pr       ice' => $order['total_price'],
            'discount_items' => $order['discount_items']
        ]);
        $useCoupons = [];
        foreach($coupons as $coupon){
            if(in_array($coupon['ucou_id'], $buyParams['use_coupons'])){
                $useCoupons[] = $coupon;
            }else{
                $priceItems['valid_coupons'][] = [
                    'ucou_id' => $coupon['ucou_id'],
                    'coup_name' => $coupon['coup_name'],
                    'coup_start_at' => $coupon['coup_start_at'],
                    'coup_end_at' => $coupon['coup_end_at']
                ];
            }
        }
        $coupon = new Coupon();
        foreach($useCoupons as $couponData){
            $coupon->load($couponData, '');
            
            $discount = $coupon->apply();
            $priceItems['total_price'] -= $discount;
            $priceItems['total_discount'] += $discount;
            $priceItems['use_coupons_des'][] = static::buildUseCouponDes($coupon);
        }
        return $priceItems;
    }
    public static function buildUseCouponDes($coupon){
        return sprintf("%s", $coupon['coup_name']);
    }
    public static function caculatePrice($order, $buyParams = []){
        $priceDiscountItems = static::caculateDiscountPrice($order, $buyParams);
        $priceCouponItems = static::caculateCouponPrice([
            'total_price' => $priceDiscountItems['total_price'],
            'total_discount' => $priceDiscountItems['total_discount'],
            'discount_items' => $priceDiscountItems['discount_items'],
        ], [
            'og_list' => $buyParams['og_list'],
            'buy_uid' => $buyParams['buy_uid'],
            'use_coupons' => ArrayHelper::getValue($buyParams, 'use_coupons', [])
        ]);
        $priceItems = array_merge($priceDiscountItems, $priceCouponItems);
        return $priceItems;
    }
    public static function buildDiscountItemDes($data){
        $ruleNameMap =  ConstMap::getConst('sr_object_type');
        return sprintf("订单原价%s,折扣为%s,优惠后价格为%s(使用%s规则-优惠%s)",
            PriceHelper::format($data['total_price']),
            PriceHelper::format($data['discount']),
            PriceHelper::format($data['total_price'] - $data['discount'])
            ,$ruleNameMap[$data['sr_object_type']]
            ,$data['sr_name']
        );
    }
    public static function buildOrderNumber(){
        list($time, $millsecond) = explode('.', microtime(true));
        $string = sprintf("OD%s%04d", date("HYisdm", $time), $millsecond);
        return $string;
    }

    public static function buildOdTitleFromGoods($ogListData){
        return count($ogListData) > 1 ?
            sprintf("%s等%s件商品", $ogListData[0]['og_name'], count($ogListData))
            :
            sprintf("%s 1件商品", $ogListData[0]['og_name']);
    }
    public static function batchInsertODiscountData($itemsData){
        return Yii::$app->db->createCommand()->batchInsert(OrderDiscount::tableName(), [
            'od_id',
            'od_discount_items',
            'od_discount_des',
        ], $itemsData)->execute();
    }
    public static function batchInsertOgData($ogListData){
        return Yii::$app->db->createCommand()->batchInsert(OrderGoods::tableName(), [
            'og_created_at',
            'og_discount_des',
            'og_discount_items',
            'og_g_id',
            'og_g_sid',
            'og_g_stype',
            'og_name',
            'og_single_price',
            'og_sku_id',
            'og_sku_index',
            'og_total_num',
            'og_total_price',
            'og_updated_at',
            'og_od_id',
        ], $ogListData)->execute();
    }
}