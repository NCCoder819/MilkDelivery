<?php

namespace App\Http\Controllers;

use App\Model\BasicModel\Address;
use App\Model\BasicModel\Customer;
use App\Model\DeliveryModel\DeliveryStation;
use App\Model\DeliveryModel\DeliveryType;
use App\Model\DeliveryModel\DSDeliveryArea;
use App\Model\DeliveryModel\DSDeliveryPlan;
use App\Model\DeliveryModel\DSProductionPlan;
use App\Model\DeliveryModel\MilkMan;
use App\Model\DeliveryModel\MilkmanBottleRefund;
use App\Model\DeliveryModel\MilkManDeliveryPlan;
use App\Model\FactoryModel\Factory;
use App\Model\FinanceModel\DSBusinessCreditBalanceHistory;
use App\Model\BasicModel\ProvinceData;
use App\Model\FactoryModel\FactoryBottleType;
use App\Model\NotificationModel\DSNotification;
use App\Model\OrderModel\OrderProduct;
use App\Model\OrderModel\SelfOrder;
use App\Model\OrderModel\SelfOrderProduct;
use App\Model\ProductModel\Product;
use App\Model\ProductModel\ProductPrice;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;

use App\Model\UserModel\Page;
use App\Model\UserModel\User;
use App\Model\SystemModel\SysLog;

use DateTime;
use DateTimeZone;
use Auth;
use Illuminate\Support\Facades\DB;
use App\Model\OrderModel\Order;
use App\Http\Requests;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Response;

use Excel;


class DSDeliveryPlanCtrl extends Controller
{
    private $kDateReport = "report_date";
    private $kDateDeliverManage = "dm_date";
    private $kDateDeliverList = "dl_date";

    /**
     * 获取配送计划数据
     * @param $deliverDate
     * @param $planResult
     * @param $checkOnly
     * @return int|mixed
     */
    private function getPlanResult($deliverDate, &$planResult, $checkOnly) {

        $current_station_id = $this->getCurrentStationId();
        $currentDate_str = getPrevDateString($deliverDate);

        // 是否已生成配送单
        $is_distributed = 0;

        // 只有考虑已签收的计划
        $DSProduction_plans = DSProductionPlan::where('station_id',$current_station_id)
            ->where('produce_end_at',$currentDate_str)
            ->where('status', DSProductionPlan::DSPRODUCTION_PRODUCE_RECEIVED)
            ->orderby('product_id')
            ->get();

        // 获取奶站配送计划
        $dsDeliveryPlans = DSDeliveryPlan::where('station_id', $current_station_id)
            ->where('deliver_at', $deliverDate)
            ->get();

        //
        // 获取昨日库存量 (定制group by)
        //
        $dsDeliveryPlansPrev = array();
        $latestInfos = DSDeliveryPlan::where('station_id', $current_station_id)
            ->where('deliver_at', '<', $deliverDate)
            ->groupBy('product_id')
            ->selectRaw('product_id, max(deliver_at) as latest')
            ->get();

        if ($latestInfos->count() > 0) {
            $dsPrevQuery = DSDeliveryPlan::where('station_id', $current_station_id)
                ->where(function ($query) use ($latestInfos) {
                    foreach ($latestInfos as $latest) {
                        $query->orWhere(function ($query) use ($latest) {
                            $query->where('product_id', $latest->product_id);
                            $query->where('deliver_at', $latest->latest);
                        });
                    }
                });

            $dsDeliveryPlansPrev = $dsPrevQuery->get();
        }

        // 查询已配送完的配送订单
        $mmDeliveryPlansFinished = MilkManDeliveryPlan::with('orderProduct')
            ->where('station_id',$current_station_id)
            ->where('deliver_at',$deliverDate)
            ->where('status', MilkManDeliveryPlan::MILKMAN_DELIVERY_PLAN_STATUS_FINNISHED)
            ->where('type',MilkManDeliveryPlan::MILKMAN_DELIVERY_PLAN_TYPE_USER)
            ->get();

        $changed_counts = MilkManDeliveryPlan::with('orderProduct')
            ->where('station_id',$current_station_id)
            ->where('deliver_at', $deliverDate)
            ->wherebetween('status',[MilkManDeliveryPlan::MILKMAN_DELIVERY_PLAN_STATUS_PASSED,MilkManDeliveryPlan::MILKMAN_DELIVERY_PLAN_STATUS_FINNISHED])
            ->where('type',MilkManDeliveryPlan::MILKMAN_DELIVERY_PLAN_TYPE_USER)
            ->get();

        // 第一，根据配送订单查询数据
        foreach($changed_counts as $cc) {

            $planProduct = null;

            $index = $cc->orderProduct->product_id;
            if (array_key_exists($index, $planResult)) {
                $planProduct = $planResult[$index];
            }

            if (!$planProduct) {
                // 初始化
                foreach($DSProduction_plans as $dp){
                    if($index == $dp->product_id){
                        $planProduct = $dp;
                        break;
                    }
                }

                // 这产品不在DSProductionPlan里面，即没提交过来的
                if (!$planProduct) {
                    $planProduct = new DSProductionPlan;
                    $planProduct->product_id = $index;
                }

                $planProduct["changed_plan_count"] = 0;
            }

            // 计算该奶品的参数
            $is_distributed = max(
                $is_distributed,
                $this->calcPlanDataForProductCore(
                    $planProduct,
                    $dsDeliveryPlans,
                    $dsDeliveryPlansPrev,
                    $mmDeliveryPlansFinished,
                    $cc
                )
            );
            if ($checkOnly && $is_distributed > 0) {
                return $is_distributed;
            }

            // 如果是没签收，订单数量的累加
            if (empty($planProduct->station_id)) {
                $planProduct->order_count += $cc->plan_count;
            }

            // 添加到主数组
            $planResult[$index] = $planProduct;
        }

        // 第二，根据提交数据查询数据
        foreach($DSProduction_plans as $dp){

            if (array_key_exists($dp->product_id, $planResult)) {
                // 这奶品以计算好了，不用再计算
                continue;
            }

            // 计算该奶品的参数
            $is_distributed = max(
                $is_distributed,
                $this->calcPlanDataForProductCore(
                    $dp,
                    $dsDeliveryPlans,
                    $dsDeliveryPlansPrev,
                    $mmDeliveryPlansFinished
                )
            );
            if ($checkOnly && $is_distributed > 0) {
                return $is_distributed;
            }

            // 添加到主数组
            $planResult[$dp->product_id] = $dp;
        }

        // 第三，只要有库存的就添加
        $prevDeliveryPlans = DSDeliveryPlan::where('station_id', $current_station_id)
            ->where('remain', '>', 0)
            ->where('deliver_at', '<', $deliverDate)
            ->distinct()
            ->get(['product_id']);

        foreach($prevDeliveryPlans as $dp){
            // 这奶品以计算好了，不用再计算
            if (array_key_exists($dp->product_id, $planResult)) {
                continue;
            }

            // 计算该奶品的参数
            $is_distributed = max(
                $is_distributed,
                $this->calcPlanDataForProductCore(
                    $dp,
                    $dsDeliveryPlans,
                    $dsDeliveryPlansPrev,
                    $mmDeliveryPlansFinished
                )
            );
            if ($checkOnly && $is_distributed > 0) {
                return $is_distributed;
            }

            // 没有库存的，不用计算
            if ($dp->dp_remain_before <= 0) {
                continue;
            }

            // 添加到主数组
            $planResult[$dp->product_id] = $dp;
        }

        // 计算配送计划调整数量
        foreach ($planResult as $planProduct) {
            $planProduct["changed_amount"] = $planProduct["changed_plan_count"] - $planProduct->order_count;
        }

        return $is_distributed;
    }

    /**
     * 显示配送管理页面
     * @param Request $request
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function showPeisongguanli(Request $request){

        $child = 'peisongguanli';
        $parent = 'shengchan';
        $current_page = 'peisongguanli';
        $pages = Page::where('backend_type','3')->where('parent_page', '0')->orderby('order_no')->get();

        $current_station_id = $this->getCurrentStationId();

        $strCurDate = getCurDateString();

        // 设置处理日期
        $deliver_date_str = $request->input('date');
        if (empty($deliver_date_str)) {
            $deliver_date_str = $strCurDate;
        }

        // 在session保存日期
        $request->session()->put($this->kDateDeliverManage, $deliver_date_str);

        // DSProductionPlan有可能不包括当天配送的所有数据、因为有配送变化量等，于是手工设置
        $planResult = array();
        $errMsg = '';

        // 如果要生成第二天的配送单，先检查当天的配送单是否已生成
        if ($deliver_date_str > $strCurDate) {
            $is_distributed = $this->getPlanResult($strCurDate, $planResult, true);
            if (empty($is_distributed)) {
                $errMsg = '没法生成当天的配送单，因为今日的配送单还没生成。';
            }
        }

        $planResult = array();
        $is_distributed = $this->getPlanResult($deliver_date_str, $planResult, false);

        // 有数量变化的配送明细，排除数量为0的配送明细
        $changed_plans = MilkManDeliveryPlan::where('station_id',$current_station_id)
            ->where('deliver_at',$deliver_date_str)
            ->whereRaw('plan_count != changed_plan_count')
            ->where('type',MilkManDeliveryPlan::MILKMAN_DELIVERY_PLAN_TYPE_USER)
            ->wherebetween('status',[MilkManDeliveryPlan::MILKMAN_DELIVERY_PLAN_STATUS_PASSED,MilkManDeliveryPlan::MILKMAN_DELIVERY_PLAN_STATUS_FINNISHED])
            ->get();

        return view('naizhan.shengchan.peisongguanli',[
            'pages'                 =>$pages,
            'child'                 =>$child,
            'parent'                =>$parent,
            'current_page'          =>$current_page,

            'is_received'           =>true, // 不管签不签收操作都是正常
            'is_reported'           =>$this->isDeliverReportFinished($current_station_id, $deliver_date_str),
            'dsproduction_plans'    =>$planResult,
            'is_distributed'        =>$is_distributed,
            'changed_plans'         =>$changed_plans,

            'date_current'          =>$deliver_date_str,
            'date_start'            =>$strCurDate,
            'date_end'              =>getDateWithOffsetString(1, $strCurDate),
            'errMsg'                =>$errMsg,
        ]);
    }

    /**
     * 配送管理页面计算一种奶品的参数
     * @param $planProduct
     * @param $dsDeliveryPlans Collection 奶站配送计划
     * @param $dsDeliveryPlansPrev array 昨日库存量
     * @param $mmDeliveryPlansFinished Collection 已配送完的配送明细
     * @param MilkManDeliveryPlan|null $mmDeliveryPlan
     * @return int
     */
    private function calcPlanDataForProductCore(&$planProduct,
                                                $dsDeliveryPlans,
                                                $dsDeliveryPlansPrev,
                                                $mmDeliveryPlansFinished,
                                                MilkManDeliveryPlan $mmDeliveryPlan = null) {
        // 是否已调配
        $nDistributed = 0;

        // 获取该奶品的配送计划相关内容
        foreach ($dsDeliveryPlans as $dsdp) {
            // 只考虑相应的奶品
            if ($dsdp->product_id != $planProduct->product_id) {
                continue;
            }

            if (!empty($dsdp->generated)) {
                $nDistributed = 1;
            }

            $planProduct["dp_retail"] = $dsdp->retail;
            $planProduct["dp_test_drink"] = $dsdp->test_drink;
            $planProduct["dp_group_sale"] = $dsdp->group_sale;
            $planProduct["dp_channel_sale"] = $dsdp->channel_sale;
            $planProduct["dp_remain"] = $dsdp->remain_final;

            break;
        }

        // 合计总数量
        if ($mmDeliveryPlan) {
            if ($nDistributed) {
                $planProduct["changed_plan_count"] += $mmDeliveryPlan->delivery_count;
            }
            else {
                $planProduct["changed_plan_count"] += $mmDeliveryPlan->changed_plan_count;
            }
        }

        // 获取该奶品的昨日库存量
        foreach ($dsDeliveryPlansPrev as $dsdp) {
            // 只考虑相应的奶品
            if ($dsdp->product_id != $planProduct->product_id) {
                continue;
            }

            $planProduct["dp_remain_before"] = $dsdp->remain_final;
            break;
        }

        // 配送业务实际配送数量
        $deliver_finished_count = 0;
        foreach($mmDeliveryPlansFinished as $dfp){
            if($dfp->orderProduct->product_id == $planProduct->product_id){
                $deliver_finished_count += $dfp->delivered_count;
                break;
            }
        }

        $planProduct["deliverd_count"] = $deliver_finished_count;

        return $nDistributed;
    }

    /**
     * 保存奶站配送信息
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function save_distribution(Request $request){

        $current_station_id = $this->getCurrentStationId();

        // 从session获取日期
        $currentDate_str = $request->session()->get($this->kDateDeliverManage);
        if (empty($currentDate_str)) {
            $currentDate_str = getCurDateString();
        }

        //
        // 设置配送员
        //
        $deliveryPlans = MilkManDeliveryPlan::where('station_id',$current_station_id)
            ->with('orderDelivery')
            ->where('deliver_at', $currentDate_str)
            ->where('type', MilkManDeliveryPlan::MILKMAN_DELIVERY_PLAN_TYPE_USER)
            ->wherebetween('status', [MilkManDeliveryPlan::MILKMAN_DELIVERY_PLAN_STATUS_PASSED,MilkManDeliveryPlan::MILKMAN_DELIVERY_PLAN_STATUS_SENT])
            ->get();

        foreach ($deliveryPlans as $dp) {
            if (empty($dp->orderDelivery->milkman)) {
                return response()->json([
                    'status' => 'fail',
                    'message' => '无法生成配送单，订单（' . $dp->orderDelivery->number . '）未匹配配送员。'
                ]);
            }

            $dp->milkman_id = $dp->orderDelivery->milkman_id;
            $dp->save();
        }

        //
        // 生成今日配送单
        //
        $table_info = json_decode($request->getContent(),true);

        foreach ($table_info as $ti){
            $delivery_distribution = DSDeliveryPlan::getDeliveryPlanGenerated(
                $current_station_id,
                $ti['product_id'],
                false,
                $currentDate_str
            );

            if (!$delivery_distribution) {
                $delivery_distribution = new DSDeliveryPlan;
                $delivery_distribution->station_id = $current_station_id;
                $delivery_distribution->deliver_at = $currentDate_str;
                $delivery_distribution->product_id = $ti['product_id'];
                $delivery_distribution->remain = $ti['remain'];
            }

            // 写入生成配送单标志
            $delivery_distribution->generated = 1;
            $delivery_distribution->save();
        }

        return response()->json(['status' => 'success']);
    }

    /**
     * 生成配送列表
     * @param Request $request
     * @return mixed
     */
    public function save_changed_distribution(Request $request){

        $table_info = json_decode($request->getContent(),true);
        foreach ($table_info as $ti){
            $changed_delivery = MilkManDeliveryPlan::find($ti['id']);
            $changed_delivery->delivery_count = $ti['delivery_count'];
            $changed_delivery->comment = $ti['comment'];
            $changed_delivery->save();
        }

        return Response::json();
    }

    /**
     * 打开配送列表
     * @param Request $request
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function showPeisongliebiao(Request $request){

        $current_station_id = $this->getCurrentStationId();
        $deliver_date_str = getCurDateString();

        $res = $this->getOrderDeliverList($current_station_id, $deliver_date_str);

        // 页面信息
        $child = 'peisongguanli';
        $parent = 'shengchan';
        $current_page = 'peisongliebiao';
        $pages = Page::where('backend_type',Page::NAIZHAN)->where('parent_page', '0')->orderby('order_no')->get();

        return view('naizhan.shengchan.peisongguanli.peisongliebiao',[
            'pages'             =>$pages,
            'child'             =>$child,
            'parent'            =>$parent,
            'current_page'      =>$current_page,
            'delivery_plans'    =>$res,
        ]);
    }

    /**
     * 查询配送列表
     * @param $station_id
     * @param $date
     * @return array
     */
    private function getOrderDeliverList($station_id, $date) {

        $delivery_plans = MilkManDeliveryPlan::join('orders as o', 'o.id', '=', 'milkmandeliveryplan.order_id')
            ->where('milkmandeliveryplan.station_id', $station_id)
            ->where('milkmandeliveryplan.deliver_at', $date)
            ->where('milkmandeliveryplan.type', MilkManDeliveryPlan::MILKMAN_DELIVERY_PLAN_TYPE_USER)
            ->whereBetween('milkmandeliveryplan.status',[MilkManDeliveryPlan::MILKMAN_DELIVERY_PLAN_STATUS_PASSED,MilkManDeliveryPlan::MILKMAN_DELIVERY_PLAN_STATUS_FINNISHED])
            ->orderBy('o.delivery_time')
            ->orderBy('o.address')
            ->get()
            ->groupBy(function($sort){
                return $sort->order_id;
            });

        $aryRes = array();
        foreach ($delivery_plans as $r=>$by_order_id) {
            // 获取订单信息
            $orderData = Order::find($r);
            $products = array();
            $is_changed = 0;
            $delivery_type = 1;
            $box_install_count = 0;     // 奶箱安装数量
            $comment = '';

            foreach ($by_order_id as $dp) {
                $name = $dp->order_product->product->simple_name;
                $count = $dp->delivery_count;
                $products[] = $name . '*' . $count;
                if ($dp->plan_count != $dp->changed_plan_count)
                    $is_changed = 1;
                $delivery_type = $dp->type;

                if ($dp->isBoxInstall()) {
                    $box_install_count = 1;
                }

                $comment = $dp->comment;
            }

            $orderData['product'] = implode(',', $products);
            if ($box_install_count > 0) {
                $orderData['product'] = $orderData['product'] . ', 奶箱*' . $box_install_count;
            }

            $orderData['changed'] = $is_changed;
            $orderData['delivery_type'] = $delivery_type;
            $orderData['comment_delivery'] = $comment;

            // 添加到主数组
            array_push($aryRes, $orderData);
        }

        return $aryRes;
    }

    /**
     * 打开自营出库页面
     * @param Request $request
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function showZiyingdingdan(Request $request){

        $current_station_id = $this->getCurrentStationId();

        $milk_mans = MilkMan::where('station_id',$current_station_id)->get();

        if (count($milk_mans) == 0) {
            return redirect()->route('naizhan_peisongliebiao')->with('page_status','没有配送员!');
        }

        // 配送日期
        $deliver_date_str = getCurDateString();

        //
        // 查询有效的产品的库存信息
        //

        // 首先，获取产品类型
        $delivery_plans = DSDeliveryPlan::where('station_id', $current_station_id)
            ->where('deliver_at', '<=', $deliver_date_str)
            ->distinct()
            ->get(['product_id']);

        // 然后，获取该产品的库存数据
        $deliveryPlansResult = array();

        foreach ($delivery_plans as $dp) {
            $dpProduct = DSDeliveryPlan::where('station_id', $current_station_id)
                ->where('deliver_at', '<=', $deliver_date_str)
                ->where('product_id', $dp->product_id)
                ->orderby('deliver_at', 'desc')
                ->first();

            $deliveryPlansResult[] = $dpProduct;
        }

        // 查新订单数量，从库存减去
        $changed_counts = MilkManDeliveryPlan::where('station_id',$current_station_id)
            ->where('deliver_at', $deliver_date_str)
            ->wherebetween('status',[MilkManDeliveryPlan::MILKMAN_DELIVERY_PLAN_STATUS_PASSED,MilkManDeliveryPlan::MILKMAN_DELIVERY_PLAN_STATUS_FINNISHED])
            ->where('type',MilkManDeliveryPlan::MILKMAN_DELIVERY_PLAN_TYPE_USER)
            ->get();

        $bIsFinishedReport = $this->isDeliverReportFinished($current_station_id, $deliver_date_str);

        foreach ($deliveryPlansResult as $dp) {
            $nOrderCount = 0;

            // 计算订单数量
            foreach($changed_counts as $cc) {
                // 只考虑本奶品的配送明细
                if ($dp->product_id != $cc->order_product->product->id) {
                    continue;
                }

                // 是否生产今日配送单
                $bGeneratedTask = false;

                if ($dp->deliver_at == $deliver_date_str) {
                    if (!empty($delivery_plans->generated)) {
                        $bGeneratedTask = true;
                    }
                }

                // 已返录
                if (!$bIsFinishedReport) {
                    if ($bGeneratedTask) {
                        $nOrderCount += $cc->delivery_count;
                    }
                    else {
                        $nOrderCount += $cc->changed_plan_count;
                    }
                }
            }

            if ($dp->deliver_at != $deliver_date_str) {
                //
                // 如果不是今日的，初始化自营数量
                //
                $dp->remain = $dp->remain_final;
                $dp->retail = 0;
                $dp->group_sale = 0;
                $dp->channel_sale = 0;
                $dp->test_drink = 0;
            }

            // 从库存减去订单数量, 但不保存
            $dp->remain -= $nOrderCount;
        }

        //
        // 查询已经发货的自营出库任务
        //
        $milkman_delivery_plans = MilkManDeliveryPlan::where('station_id',$current_station_id)
            ->where('deliver_at',$deliver_date_str)
            ->where('type', '<>', MilkManDeliveryPlan::MILKMAN_DELIVERY_PLAN_TYPE_USER)
            ->get()
            ->groupBy(function($sort){
                return $sort->order_id;
            });

        $res = array();
        foreach($milkman_delivery_plans as $o=>$dps_by_order) {
            $res[$o] = SelfOrder::find($o);

            $products = array();
            $is_changed = 0;
            $delivery_type = 1;
            $milk_man = '';
            $comment = '';

            foreach($dps_by_order as $dp) {
                $name = $dp->order_product->product->simple_name;
                $count = $dp->delivery_count;
                $products[] = $name.'*'.$count;
                if($dp->plan_count != $dp->changed_plan_count)
                    $is_changed = 1;
                $delivery_type = $dp->type;
                $milk_man = $dp->milkman->name;
                $comment = $dp->comment;
            }
            $res[$o]['product'] = implode(',', $products);
            $res[$o]['changed'] = $is_changed;
            $res[$o]['delivery_type'] = $delivery_type;
            $res[$o]['milkman_name'] = $milk_man;
            $res[$o]['comment_delivery'] = $comment;
        }

        $dsdeliveryarea  = DSDeliveryArea::where('station_id',$current_station_id)->get();

        $street = array();
        $i = 0;
        foreach ($dsdeliveryarea as $da){
            $flag = 0;
            $cur_addr = explode(" ",$da->address);
            if($i == 0){
                $street[$i] = $cur_addr[3];
                $i++;
            }
            for($j = 0; $j < $i; $j++){
                if($street[$j] == $cur_addr[3]){
                    $flag = 1;
                }
            }
            if($flag == 0){
                $street[$i] = $cur_addr[3];
                $i++;
            }
        }

        $current_district = DeliveryStation::find($current_station_id)->address;
        $current_district = explode(" ",$current_district);
        $show_district = $current_district[0].$current_district[1].$current_district[2];
        $addr_district = $current_district[0].' '.$current_district[1].' '.$current_district[2];
        $province = ProvinceData::all();

        // 添加系统日志
        $this->addSystemLog(User::USER_BACKEND_STATION, '自营出库', SysLog::SYSLOG_OPERATION_VIEW);

        // 页面信息
        $child = 'ziyingdingdan';
        $parent = 'shengchan';
        $current_page = 'ziyingdingdan';
        $pages = Page::where('backend_type','3')->where('parent_page', '0')->orderby('order_no')->get();

        return view('naizhan.shengchan.peisongguanli.ziyingdingdan',[
            'pages'                     =>$pages,
            'child'                     =>$child,
            'parent'                    =>$parent,
            'current_page'              =>$current_page,

            'delivery_plans'            =>$deliveryPlansResult,
            'streets'                   =>$street,
            'milk_man'                  =>$milk_mans,
            'current_district'          =>$show_district,
            'addr_district'             =>$addr_district,
            'province'                  =>$province,
            'milkman_delivery_plans'    =>$res,
        ]);
    }

    public function getXiaoquName(Request $request){
        if ($request->ajax()) {
            $street_name = $request->input('street_name');
            $current_station_id = Auth::guard('naizhan')->user()->station_id;
            $dsdeliveryarea  = DSDeliveryArea::where('station_id',$current_station_id)->get();

            $xiaoqu = array();
            $i = 0;
            foreach ($dsdeliveryarea as $da){
                $cur_addr = explode(" ",$da->address);
                if($cur_addr[3] == $street_name){
                    $xiaoqu[$i] = $cur_addr[4];
                    $i++;
                }
            }
            return response()->json(['status' => 'success', 'xiaoqus' => $xiaoqu]);
        }
    }

    /**
     * 保存库存信息
     * @param Request $request
     * @return mixed
     */
    public function saveStock(Request $request) {

        $current_station_id = $this->getCurrentStationId();

        $aryProduct = $request->input('product');

        foreach ($aryProduct as $product) {
            // 如果已生成配送单信息，退出
            if (DSDeliveryPlan::getDeliveryPlanGenerated($current_station_id, $product['id'], false)) {
                return Response::json(['status'=>"success"]);
            }

            $delivery_distribution = new DSDeliveryPlan;
            $delivery_distribution->station_id = $current_station_id;
            $delivery_distribution->deliver_at = getCurDateString();
            $delivery_distribution->product_id = $product['id'];
            $delivery_distribution->remain = $product['count'];
            $delivery_distribution->save();
        }

        return Response::json(['status'=>"success"]);
    }

    /**
     * 保存自营出库数据
     * @param Request $request
     * @return mixed
     */
    public function saveZiyingdingdan(Request $request){
        $current_station_id = $this->getCurrentStationId();

        $deliver_date_str = getCurDateString();

        $customer_name = $request->input('customer_name');
        $address = $request->input('address');
        $type = $request->input('type');
        $phone = $request->input('phone');
        $milkman_id = $request->input('milkman_id');
        $deliver_time = $request->input('deliver_time');
        $product_id = $request->input('product_id');
        $product_count = $request->input('product_count');
        $comment = $request->input('comment');

        $self_orders = new SelfOrder;
        $self_orders->station_id = $current_station_id;
        $self_orders->customer_name = $customer_name;
        $self_orders->deliver_at = $deliver_date_str;
        $self_orders->phone = $phone;
        $self_orders->address = $address;
        $self_orders->delivery_time = $deliver_time;
        $self_orders->save();

        $order_id = $self_orders->id;

        for($i=0; $i < count($product_count)-1; $i++){
            $self_order_products = new SelfOrderProduct;
            $self_order_products->order_id = $order_id;
            $self_order_products->product_id = $product_id[$i];
            $self_order_products->count = $product_count[$i];
            $product_price[$i] = ProductPrice::priceTemplateFromAddress($product_id[$i], $address);
            $self_order_products->price = $product_price[$i];
            $self_order_products->save();

            $order_product_id = $self_order_products->id;

            $milkman_delivery_plans = new MilkManDeliveryPlan;
            $milkman_delivery_plans->milkman_id = $milkman_id;
            $milkman_delivery_plans->station_id = $current_station_id;
            $milkman_delivery_plans->order_id = $order_id;
            $milkman_delivery_plans->order_product_id = $order_product_id;
            $milkman_delivery_plans->deliver_at = $deliver_date_str;
            $milkman_delivery_plans->status = MilkManDeliveryPlan::MILKMAN_DELIVERY_PLAN_STATUS_SENT;
            $milkman_delivery_plans->delivery_count = $product_count[$i];
            $milkman_delivery_plans->type = $type;
            $milkman_delivery_plans->comment = $comment;
            $milkman_delivery_plans->save();

            // 更新
            $deliveryPlan = DSDeliveryPlan::getDeliveryPlanGenerated($current_station_id, $product_id[$i], $deliver_date_str, false);
            if ($deliveryPlan) {
                $deliveryPlan->increaseSelfDelivery($type, $product_count[$i]);
            }
        }

        return Response::json(['status'=>"success"]);
    }

    /**
     * 打开今日配送单
     * @param Request $request
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function showJinripeisongdan(Request $request){

        $current_station_id = $this->getCurrentStationId();

        // 配送日期，默认是今日
        $deliver_date_str = $request->input('current_date');
        if (empty($deliver_date_str)){
            // 从session获取日期
            $deliver_date_str = $request->session()->get($this->kDateDeliverManage);
            if (empty($deliver_date_str)) {
                $deliver_date_str = getCurDateString();
            }
        }

        // 在session保存日期
        $request->session()->put($this->kDateDeliverList, $deliver_date_str);

        $child = 'jinripeisongdan';
        $parent = 'shengchan';
        $current_page = 'jinripeisongdan';
        $pages = Page::where('backend_type','3')->where('parent_page', '0')->orderby('order_no')->get();

        $milkman_info = array();

        $deliveryPlan = $this->getMilkmanDeliveryQuery($current_station_id, $deliver_date_str, false)->first();

        // 只有生成了配送列表之后才显示今日配送单
        if ($deliveryPlan && !DSDeliveryPlan::getDeliveryPlanGenerated($current_station_id, $deliveryPlan->order_product->product_id, true, $deliver_date_str)) {
            $strAlertMsg = '您还没有生成今日配送单，请进入配送管理页面，去生成配送列表。';

            return view('naizhan.shengchan.jinripeisongdan',[
                'pages'         =>$pages,
                'child'         =>$child,
                'parent'        =>$parent,
                'current_page'  =>$current_page,

                'milkman_info'  =>$milkman_info,
                'alert_msg'     =>$strAlertMsg,
                'date'          =>$deliver_date_str,
            ]);
        }

        // 配送员
        $milkmans = MilkMan::where('is_active', 1)
            ->where('station_id', $current_station_id)
            ->get();

        // 配送任务根据配送员分组
        $milkman_delivery_plans = $this->getMilkmanDeliveryQuery($current_station_id, $deliver_date_str)
            ->with(array('orderDelivery' => function($query) {
                $query->orderBy('delivery_time');
                $query->orderBy('address');
                $query->with(['deliveryArea' => function($query) {
                    $query->with('milkmanDeliveryArea');
                }]);
            }))
            ->get()
            ->groupBy(function ($sort) {
                return $sort->milkman_id;
            });

        // 配送员统计
        $countsDelivery = MilkManDeliveryPlan::where('station_id', $current_station_id)
            ->join('orderproducts as op', 'milkmandeliveryplan.order_product_id', '=', 'op.id')
            ->join('products as p', 'op.product_id', '=', 'p.id')
            ->where('deliver_at', $deliver_date_str)
            ->wherebetween('milkmandeliveryplan.status',[MilkManDeliveryPlan::MILKMAN_DELIVERY_PLAN_STATUS_PASSED,MilkManDeliveryPlan::MILKMAN_DELIVERY_PLAN_STATUS_FINNISHED])
            ->groupBy('milkmandeliveryplan.type', 'op.product_id', 'milkmandeliveryplan.milkman_id')
            ->selectRaw('milkmandeliveryplan.milkman_id, sum(delivery_count) as deliverCount, type, op.product_id, p.simple_name')
            ->get();

        $productsData = array();

        foreach ($countsDelivery as $cd) {
            // 添加配送员的统计信息
            $productsData[$cd->milkman_id][$cd->product_id]['name'] = $cd->simple_name;
            $productsData[$cd->milkman_id][$cd->product_id][$cd->type] = $cd->deliverCount;
        }

        foreach ($milkman_delivery_plans as $m => $dps_by_milkman) {
            // 变化量统计数据
            $changestatus = array();
            $changestatus['new_order_amount'] = 0;
            $changestatus['new_changed_order_amount'] = 0;
            $changestatus['milkbox_amount'] = 0;

            $delivery_info = array();
            $comment = '';

            // 配送任务根据订单分组
            $regular_delivers = $dps_by_milkman->sortBy(function ($sort) {
                    return $sort->orderDelivery->address;
                })
                ->groupBy(function ($sort) {
                    return $sort->orderDelivery->address;
                });

            foreach ($regular_delivers as $r => $byAddress) {
                // 获取订单信息
                $orderData = array();

                $products = array();
                $is_changed = 0;
                $delivery_type = MilkManDeliveryPlan::MILKMAN_DELIVERY_PLAN_TYPE_USER;

                $flag = 0;                  // 是否第一次配送
                $box_install_count = 0;     // 奶箱安装数量

                foreach ($byAddress as $dp) {
                    if (empty($orderData)) {
                        $orderData = $dp->orderDelivery;

                        $orderData['notice'] = MilkManDeliveryPlan::NOTICE_NONE;
                        if ($dp->deliver_at == $orderData->getDeliveryStartDate()) {
                            $orderData['notice'] = MilkManDeliveryPlan::NOTICE_FIRST_DEVLIVER;
                        }
                        else if ($dp->deliver_at == $orderData->getDeliveryAlmostEndDate()) {
                            $orderData['notice'] = MilkManDeliveryPlan::NOTICE_ALMOST_END;
                        }
                        else if ($dp->deliver_at == $orderData->order_end_date) {
                            $orderData['notice'] = MilkManDeliveryPlan::NOTICE_END_TODAY;
                        }
                    }

                    $name = $dp->order_product->product->simple_name;
                    $count = $dp->delivery_count;
                    $nRemain = $dp->order_product->getRemainCount($deliver_date_str) - $count;
                    $products[] = $name . '*' . $count . '（剩' . $nRemain . '）';

                    if ($dp->plan_count != $dp->changed_plan_count)
                        $is_changed = 1;

                    $delivery_type = $dp->type;
                    $flag = max($flag, $dp->flag);

                    if ($dp->flag && $orderData->milk_box_install) {
                        $box_install_count = 1;
                    }

                    $comment = $dp->comment;

                    // 计算变化量统计数据
                    if ($orderData['notice'] == MilkManDeliveryPlan::NOTICE_FIRST_DEVLIVER) {
                        // 第一次配送的数量合计
                        $changestatus['new_order_amount'] += $dp->delivery_count;
                    }
                    if($dp->flag == MilkManDeliveryPlan::MILKMAN_DELIVERY_PLAN_FLAG_FIRST_ON_ORDER_RULE_CHANGE) {
                        $changestatus['new_changed_order_amount'] += $dp->delivery_count;
                    }
                }

                $orderData['products'] = $products;
                if ($box_install_count > 0) {
                    $orderData['product'] = $orderData['product'] . ', 奶箱*' . $box_install_count;

                    // 计算变化量统计数据
                    $changestatus['milkbox_amount']++;
                }

                $orderData['changed'] = $is_changed;
                $orderData['delivery_type'] = $delivery_type;
                $orderData['flag'] = $flag;
                $orderData['comment_delivery'] = $comment;

                //
                // 添加到主数组
                //
                $this->addToDeliveryInfoWithSort($delivery_info, $orderData);
            }

            $milkman_info[$m]['delivery_info'] = $delivery_info;

            $milkman_info[$m]['milkman_id'] = $m;
            $milkman_info[$m]['milkman_name'] = "";
            $milkman_info[$m]['milkman_number'] = "";

            // 配送员信息
            foreach ($milkmans as $mm) {
                if ($mm->id == $m) {
                    $milkman_info[$m]['milkman_name'] = $mm->name;
                    $milkman_info[$m]['milkman_number'] = $mm->phone;
                    break;
                }
            }

            $milkman_info[$m]['milkman_products'] = $productsData[$m];
            $milkman_info[$m]['milkman_changestatus'] = $changestatus;
        }

        // 在session保存数据
        $request->session()->put('deliver_list', $milkman_info);
        return view('naizhan.shengchan.jinripeisongdan',[
            'pages'         =>$pages,
            'child'         =>$child,
            'parent'        =>$parent,
            'current_page'  =>$current_page,

            'milkman_info'  =>$milkman_info,
            'date'          =>$deliver_date_str,
        ]);
    }

    /**
     * 导出今日配送单
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\BinaryFileResponse
     */
    public function exportDeliverList(Request $request) {

        $nFactoryId = $this->getCurrentFactoryId(false);
        $strFactory = Factory::find($nFactoryId)->name;

        // 从session获取数据
        $milkmanInfo = $request->session()->get('deliver_list');
        $deliver_date_str = $request->session()->get($this->kDateDeliverList);

        Excel::create('deliverList', function ($excel) use ($strFactory, $milkmanInfo, $deliver_date_str) {
            $excel->sheet('今日配送单' . $deliver_date_str, function ($sheet) use ($strFactory, $milkmanInfo, $deliver_date_str) {
                $nRow = 1;

                // 添加奶厂名称
                $sheet->appendRow([$strFactory]);
                $sheet->mergeCells('A' . $nRow . ':G' . $nRow);
                $sheet->cell('A' . $nRow, function($cell) {
                    $cell->setFontSize(16);
                    $cell->setFontWeight('bold');
                    $cell->setAlignment('center');
                });
                $nRow++;

                // 空行
                $sheet->appendRow(['']);
                $nRow++;

                $rowHeader = ['序号', '地址', '配送内容', '收货人', '配送时间', '电话', '备注'];
                foreach ($milkmanInfo as $mi) {
                    // 配送员信息
                    $sheet->appendRow(['配送员:   ' . $mi['milkman_name'] . ' ' .$mi['milkman_number']]);
                    $sheet->mergeCells('A' . $nRow . ':G' . $nRow);
                    $nRow++;

                    // 汇总信息
                    $strTotalCounts = "";
                    foreach ($mi['milkman_products'] as $mp) {
                        $nCount = getEmptyArrayValue($mp, \App\Model\DeliveryModel\MilkManDeliveryPlan::MILKMAN_DELIVERY_PLAN_TYPE_USER)
                            + getEmptyArrayValue($mp, \App\Model\DeliveryModel\MilkManDeliveryPlan::MILKMAN_DELIVERY_PLAN_TYPE_TESTDRINK)
                            + getEmptyArrayValue($mp, \App\Model\DeliveryModel\MilkManDeliveryPlan::MILKMAN_DELIVERY_PLAN_TYPE_GROUP)
                            + getEmptyArrayValue($mp, \App\Model\DeliveryModel\MilkManDeliveryPlan::MILKMAN_DELIVERY_PLAN_TYPE_CHANNEL)
                            + getEmptyArrayValue($mp, \App\Model\DeliveryModel\MilkManDeliveryPlan::MILKMAN_DELIVERY_PLAN_TYPE_RETAIL);
                        $strTotalCounts .= $mp['name'] . ":" . $nCount . "  ";
                    }
                    $sheet->appendRow([$strTotalCounts]);
                    $sheet->mergeCells('A' . $nRow . ':G' . $nRow);
                    $nRow++;

                    // 配送内容
                    $sheet->appendRow($rowHeader);
                    $nRow++;
                    $i = 0;
                    foreach ($mi['delivery_info'] as $oi) {
                        $rowData = [];

                        // 序号
                        $i++;

                        if ($oi['notice'] == MilkManDeliveryPlan::NOTICE_FIRST_DEVLIVER) {
                            $rowData[] = $i . ' 第一次配送';
                        }
                        else if ($oi['notice'] == MilkManDeliveryPlan::NOTICE_ALMOST_END) {
                            $rowData[] = $i . ' 即将到期';
                        }
                        else if ($oi['notice'] == MilkManDeliveryPlan::NOTICE_END_TODAY) {
                            $rowData[] = $i . ' 今日到期';
                        }
                        else {
                            $rowData[] = $i;
                        }

                        // 地址
                        $rowData[] = $oi->getAddressSmall(Address::LEVEL_VILLAGE);

                        // 配送内容
                        $strDeliver = "";
                        foreach($oi->products as $pd) {
                            $strDeliver .= $pd . " ";
                        }
                        $rowData[] = $strDeliver;

                        // 收件人
                        if ($oi->delivery_type == MilkManDeliveryPlan::MILKMAN_DELIVERY_PLAN_TYPE_USER) {
                            $strCustomer = $oi->customer->name;
                        }
                        else {
                            $strCustomer = $oi->customer_name;
                        }
                        $rowData[] = $strCustomer;

                        // 配送时间
                        $rowData[] = $deliver_date_str . " " . $oi->getDeliveryTimeDesc();

                        // 电话
                        $rowData[] = $oi->phone;

                        // 备注
                        $rowData[] = $oi->comment_delivery;

                        $sheet->appendRow($rowData);
                        $nRow++;
                    }

                    // 空行
                    $sheet->appendRow(['']);
                    $nRow++;
                }
            });
        })->store('xls', 'exports');

        return \response()->download(public_path() . '/exports/deliverList.xls')->deleteFileAfterSend(true);
    }

    /**
     * 处理多余量
     * @param $mkdp
     */
    public function undelivered_process($mkdp)
    {
        $undelivered_count = $mkdp->changed_plan_count - $mkdp->delivered_count;

        return $mkdp->order_product->processExtraCount($mkdp, $undelivered_count);
    }

    /**
     * 是否已经反录
     * @param $milkman_id
     * @param $strDate
     * @return bool
     */
    private function isDidReport($milkman_id, $strDate) {

        $nBottleReport = MilkmanBottleRefund::where('milkman_id',$milkman_id)
            ->where('time', $strDate)
            ->count();

        return $nBottleReport > 0 ? true : false;
    }

    /**
     * 获取查询当日配送明细的条件
     * @param $stationId
     * @param $deliverAt
     * @param bool $generated
     * @return mixed
     */
    private function getMilkmanDeliveryQuery($stationId, $deliverAt, $generated = true) {
        $queryDeliveryPlan = MilkManDeliveryPlan::where('station_id', $stationId)
            ->where('deliver_at', $deliverAt)
            ->where('type', MilkManDeliveryPlan::MILKMAN_DELIVERY_PLAN_TYPE_USER)
            ->wherebetween('status',[MilkManDeliveryPlan::MILKMAN_DELIVERY_PLAN_STATUS_PASSED,MilkManDeliveryPlan::MILKMAN_DELIVERY_PLAN_STATUS_FINNISHED]);

        if ($generated) {
            $queryDeliveryPlan->whereNotNull('milkman_id');
        }

        return $queryDeliveryPlan;
    }

    /**
     * 打开配送反录页面
     * @param Request $request
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function showPeisongfanru(Request $request){

        $current_station_id = $this->getCurrentStationId();
        $current_factory_id = $this->getCurrentFactoryId(false);

        // 配送日期，默认是今日
        $deliver_date_str = $request->input('current_date');
        if (empty($deliver_date_str)){
            $deliver_date_str = getCurDateString();
        }

        // 在session保存日期
        $request->session()->put($this->kDateReport, $deliver_date_str);

        // 页面信息
        $child = 'peisongfanru';
        $parent = 'shengchan';
        $current_page = 'peisongfanru';
        $pages = Page::where('backend_type','3')->where('parent_page', '0')->orderby('order_no')->get();

        // 是否已生成配送列表？
        $deliveryPlan = $this->getMilkmanDeliveryQuery($current_station_id, $deliver_date_str, true)->first();

        // 只有生成了配送列表之后才显示反录
        if (!$deliveryPlan) {
            $aryView = [
                'pages'                     =>$pages,
                'child'                     =>$child,
                'parent'                    =>$parent,
                'current_page'              =>$current_page,

                'delivery_info'             =>array(),
                'milkman'                   =>array(),
                'deliver_date'              =>$deliver_date_str,
                'current_milkman'           =>0,
                'is_todayrefund'            =>false
            ];

            // 没生成今日配送单
            if (!DSDeliveryPlan::getDeliveryPlanGenerated($current_station_id, 0, true, $deliver_date_str)) {
                $aryView['alert_msg'] = '您还没有生成今日配送单，请进入配送管理页面，去生成配送列表。';
            }

            return view('naizhan.shengchan.peisongfanru', $aryView);
        }

        //
        // 配送员列表
        //

        // 查询, 没返录的配送员排在前面
        $deliveryPlanById = $this->getMilkmanDeliveryQuery($current_station_id, $deliver_date_str)
            ->orderBy('status')
            ->groupBy('milkman_id')
            ->get(['milkman_id']);

        $aryMilkmanId = array();
        foreach ($deliveryPlanById as $dp) {
            $aryMilkmanId[] = $dp->milkman_id;
        }

        $milkman = MilkMan::whereIn('id', $aryMilkmanId)->get(['id', 'name']);
        $current_milkman = $request->input('milkman_id');

        //
        // 查询瓶框数据
        //
        $station_addr = DeliveryStation::find($current_station_id)->address;
        $station_addr = explode(' ',$station_addr);
        $station_addr = $station_addr[0]." ".$station_addr[1]." ".$station_addr[2];

        $bottle_types = DB::select(DB::raw("select DISTINCT p.bottle_type from products p, productprice pp
                    where p.id = pp.product_id and p.factory_id = $current_factory_id and pp.sales_area LIKE '%$station_addr%'"));

        // 配送员信息，默认是第一个
        if (empty($current_milkman)) {
            $current_milkman = $aryMilkmanId[0];
        }

        // 奶瓶回收
        $milkman_bottle_refunds = MilkmanBottleRefund::where('milkman_id',$current_milkman)
            ->where('time',$deliver_date_str)
            ->get(['count','bottle_type']);

        $queryDeliveryPlan = $this->getMilkmanDeliveryQuery($current_station_id, $deliver_date_str)
            ->where('milkman_id',$current_milkman)
            ->with(array('orderDelivery' => function($query) {
                $query->orderBy('delivery_time');
                $query->orderBy('address');
                $query->with(['deliveryArea' => function($query) {
                    $query->with('milkmanDeliveryArea');
                }]);
            }));

        $milkman_delivery_plans = $queryDeliveryPlan->get();

        //
        // 查询配送数据
        //
        $delivery_info = array();

        // 根据订单分类
        $mdp_by_order = $milkman_delivery_plans->sortBy(function ($sort) {
                return $sort->orderDelivery->address;
            })
            ->groupBy(function ($sort){
                return $sort->order_id;
            });

        $nNotReportCount = 0;

        foreach ($mdp_by_order as $r=>$by_order_id){
            // 获取订单信息
            $orderData = Order::find($r);
            $products = array();
            $milkboxinstall = 0;

            foreach($by_order_id as $pro=>$dp) {
                $name = $dp->order_product->product->simple_name;
                $count = $dp->delivery_count;
                $products[$pro]['name'] = $name;
                $products[$pro]['count'] = $count;
                $products[$pro]['id'] = $dp->id;
                $products[$pro]['delivered_count'] = $dp->delivered_count;
                $products[$pro]['report'] = $dp->report;
                $products[$pro]['comment'] = $dp->comment;
                $products[$pro]['status'] = $dp->status;

                if ($dp->status != MilkManDeliveryPlan::MILKMAN_DELIVERY_PLAN_STATUS_FINNISHED) {
                    $nNotReportCount++;
                }

                if($dp->isBoxInstall())
                    $milkboxinstall = 1;
            }

            $orderData['product'] = $products;
            $orderData['milkbox_install'] = $milkboxinstall;

            //
            // 添加到主数组
            //
            $this->addToDeliveryInfoWithSort($delivery_info, $orderData);
        }

        // 添加系统日志
        $this->addSystemLog(User::USER_BACKEND_STATION, '配送反录', SysLog::SYSLOG_OPERATION_VIEW);

        return view('naizhan.shengchan.peisongfanru',[
            'pages'                     =>$pages,
            'child'                     =>$child,
            'parent'                    =>$parent,
            'current_page'              =>$current_page,

            'delivery_info'             =>$delivery_info,
            'milkman'                   =>$milkman,
            'deliver_date'              =>$deliver_date_str,
            'current_milkman'           =>$current_milkman,
            'bottle_types'              =>$bottle_types,
            'milkman_bottle_refunds'    =>$milkman_bottle_refunds,
            'is_todayrefund'            =>$this->isDidReport($current_milkman, $deliver_date_str),
            'saveAvailable'             => ($nNotReportCount > 0),
        ]);
    }

    /**
     * 添加到主配送数组
     * @param $delivery_info
     * @param $orderData
     */
    public function addToDeliveryInfoWithSort(&$delivery_info, $orderData) {
        //
        // 添加到主数组
        //
        $nIndex = 0;
        for ($i = count($delivery_info) - 1; $i >= 0; $i--) {
            $di = $delivery_info[$i];

            // 只考虑相同的街道
            if ($di->street_id != $orderData->street_id) {
                break;
            }

            // 决定该对象的排序
            if ($di->getDeliverAddressOrder() < $orderData->getDeliverAddressOrder()) {
                break;
            }
            else {
                $nIndex = $i;
            }
        }

        // 根据排序添加
        if ($nIndex > 0) {
            $aryInsert = array($orderData);
            array_splice($delivery_info, $nIndex, 0, $aryInsert);
        }
        else {
            array_push($delivery_info, $orderData);
        }
    }

    /**
     * 配送员回收平框
     * @param Request $request
     */
    public function savebottleboxPeisongfanru(Request $request){

        $current_milkman_id = $request->input('milkman_id');
        $bottle_type = $request->input('bottle_type');
        $count = $request->input('count');

        // 从session获取日期
        $strDate = $request->session()->get($this->kDateReport);

        $bottle_refunds = new MilkmanBottleRefund;
        $bottle_refunds->milkman_id = $current_milkman_id;
        $bottle_refunds->bottle_type = $bottle_type;
        $bottle_refunds->time = $strDate;
        $bottle_refunds->count = $count;
        $bottle_refunds->save();

        return Response::json(['status'=>"success"]);;
    }

    /**
     * 查看是否完成返录
     * @param $stationId
     * @param $date
     * @param bool $strictMode 严格模式
     * @return bool
     */
    public function isDeliverReportFinished($stationId, $date, $strictMode = false) {
        $bRes = true;

        if ($strictMode) {
            $bRes = !(MilkManDeliveryPlan::where('station_id', $stationId)
                ->where('deliver_at', $date)
                ->wherebetween('status', [MilkManDeliveryPlan::MILKMAN_DELIVERY_PLAN_STATUS_PASSED, MilkManDeliveryPlan::MILKMAN_DELIVERY_PLAN_STATUS_SENT])
                ->where('type', MilkManDeliveryPlan::MILKMAN_DELIVERY_PLAN_TYPE_USER)
                ->exists());
        }
        else {
            // 获取配送员
            $deliveryPlanByMilkman = MilkManDeliveryPlan::where('station_id', $stationId)
                ->where('deliver_at', $date)
                ->wherebetween('status', [MilkManDeliveryPlan::MILKMAN_DELIVERY_PLAN_STATUS_PASSED, MilkManDeliveryPlan::MILKMAN_DELIVERY_PLAN_STATUS_SENT])
                ->where('type', MilkManDeliveryPlan::MILKMAN_DELIVERY_PLAN_TYPE_USER)
                ->distinct()
                ->get(['milkman_id']);

            foreach ($deliveryPlanByMilkman as $dp) {
                // 检查所有配送员是否都返录完
                if (!$this->isDidReport($dp->milkman_id, $date)) {
                    // 还没返录完，退出
                    $bRes = false;
                    break;
                }
            }
        }

        return $bRes;
    }

    /**
     * 保存反录
     * @param Request $request
     * @return mixed
     */
    public function confirmdeliveryPeisongfanru(Request $request){

        $nStationId = $this->getCurrentStationId();

        // 从session获取日期
        $deliver_date_str = $request->session()->get($this->kDateReport);

        $deliveryPlans = [];
        $bResult = true;

        $table_info = json_decode($request->getContent(),true);
        foreach ($table_info as $ti){
            $mdp_id = $ti['mdp_id'];
            $delivered_count = $ti['delivered_count'];
            // 这是反录情况内容，不是备注内容
            $report = $ti['report'];

            $mdp = MilkManDeliveryPlan::find($mdp_id);

            //
            // 更新配送明细数据
            //
            $bSuccess = true;

            $mdp->delivered_count = $delivered_count;
            $mdp->status = MilkManDeliveryPlan::MILKMAN_DELIVERY_PLAN_STATUS_FINNISHED;
            $mdp->report = $report;

            if($mdp->type == MilkManDeliveryPlan::MILKMAN_DELIVERY_PLAN_TYPE_USER){

                // 计算订单剩余金额
                $order = $mdp->orderDelivery;
                $order->remaining_amount = $order->remaining_amount - $mdp->delivered_count * $mdp->product_price;

                // 如果当天没有配送完，顺延处理
                if ($mdp->delivered_count != $mdp->changed_plan_count){
                    $bSuccess = $this->undelivered_process($mdp);

                    Log::info("返录多余量 -> mdp: " . $mdp->id
                        . ", changed_plan_count: " . $mdp->changed_plan_count
                        . ", delivered_count: " . $mdp->delivered_count
                        . ", result: " . $bSuccess);
                }

                if ($bSuccess) {
                    // 计算剩下数量
                    $total_order_counts = MilkManDeliveryPlan::where('order_id', $order->id)
                        ->whereBetween('status', [MilkManDeliveryPlan::MILKMAN_DELIVERY_PLAN_STATUS_PASSED, MilkManDeliveryPlan::MILKMAN_DELIVERY_PLAN_STATUS_SENT])
                        ->count();

                    // 如果没有剩下的，订单变成已完成
                    if ($total_order_counts <= $mdp->delivered_count) {
                        $order->status = Order::ORDER_FINISHED_STATUS;

                        // 订单余额到客户账户里加回去
                        $order->customer->remain_amount += $order->remaining_amount;
                        $order->customer->save();
                    }
                    $order->save();

                    //
                    // 更新库存数据
                    //
                    if (!isset($deliveryPlans[$mdp->getProductId()])) {
                        $deliveryPlans[$mdp->getProductId()] = 0;
                    }

                    $deliveryPlans[$mdp->getProductId()] += $delivered_count;
                }
            }

            if ($bSuccess) {
                $mdp->save();
            }

            $bResult &= $bSuccess;
        }

        // 更新数据库, 减少剩余数量
        foreach ($deliveryPlans as $pid=>$dp) {
            DSDeliveryPlan::where('station_id', $nStationId)
                ->where('deliver_at', '>=', $deliver_date_str)
                ->where('product_id', $pid)
                ->decrement('remain', $dp);
        }

        //
        // 财务计算：返还自营账户余额调整
        //

        // 返录全部配送才计算返还问题
        if ($this->isDeliverReportFinished($nStationId, $deliver_date_str, true)) {
            // 查询已配送完的配送订单
            $deliver_finished_plans = MilkManDeliveryPlan::where('station_id', $nStationId)
                ->where('deliver_at', $deliver_date_str)
                ->where('status', MilkManDeliveryPlan::MILKMAN_DELIVERY_PLAN_STATUS_FINNISHED)
                ->where('type', MilkManDeliveryPlan::MILKMAN_DELIVERY_PLAN_TYPE_USER)
                ->get();

            // 配送业务实际配送数量
            $aryReceiveCount = array();
            foreach ($deliver_finished_plans as $dfp) {
                $aryReceiveCount[strval($dfp->order_product->product->id)] = 0;
            }
            foreach ($deliver_finished_plans as $dfp) {
                $aryReceiveCount[strval($dfp->order_product->product->id)] += $dfp->delivered_count;
            }

            $plan_info = DSProductionPlan::where('produce_end_at', getPrevDateString($deliver_date_str))
                ->where('station_id', $this->getCurrentStationId())
                ->where('status', '>=', DSProductionPlan::DSPRODUCTION_PASSED_PLAN)
                ->get();

            $dCostReturnTotal = 0;
            foreach ($plan_info as $pi) {
                // 没有配送此奶品，默认配送量是0
                if (!isset($aryReceiveCount[strval($pi->product_id)])) {
                    $aryReceiveCount[strval($pi->product_id)] = 0;
                }

                // 没有发货就当成0计算
                if (empty($pi->actual_count)) {
                    $pi->actual_count = 0;
                }

                // 自营订单实际扣款
                $dCostReal = ($pi->actual_count - $aryReceiveCount[strval($pi->product_id)]) * $pi->settle_product_price;
                $dCostReturn = -$pi->getSelfOrderMoney() + $dCostReal;

                $dCostReturnTotal += $dCostReturn;
            }

            // 没有返还金额，不要添加返还记录
            if ($dCostReturnTotal != 0) {
                // 添加返还记录
                $balancehistory = new DSBusinessCreditBalanceHistory;
                $balancehistory->station_id = $nStationId;
                $balancehistory->type = DSBusinessCreditBalanceHistory::DSBCBH_OUT_RETURN;
                $balancehistory->io_type = DSBusinessCreditBalanceHistory::DSBCBH_OUT;
                $balancehistory->amount = $dCostReturnTotal;
                $balancehistory->return_amount = 0;
                $balancehistory->save();

                // 从自营账号扣款
                $delivery_transation = DeliveryStation::find($nStationId);
                $delivery_transation->addSelfOrderAccount($balancehistory->amount);

                // 添加奶站通知
                $notification = new NotificationsAdmin();
                $notification->sendToStationNotification($nStationId,
                    DSNotification::CATEGORY_TRANSACTION,
                    "回报金钱",
                    "您本次订单计划多余扣除货款" . $dCostReturnTotal . "元已退回您的自营账户。");
            }
        }

        $jsonRet = Response::json(['status'=>"success"]);
        if (!$bResult) {
            $jsonRet = Response::json([
                "status"    => "fail",
                "message"   => "没法处理发生变化的返录数量",
            ]);
        }

        return Response::json(['status'=>"success"]);
    }
}
