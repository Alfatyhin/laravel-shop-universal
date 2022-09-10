<?php


namespace App\Services;


use AmoCRM\Models\CustomFieldsValues\ValueCollections\PriceCustomFieldValueCollection;
use AmoCRM\Models\CustomFieldsValues\ValueModels\PriceCustomFieldValueModel;
use App\Mail\NewOrder;
use App\Models\AppErrors;
use App\Models\Clients;
use App\Models\Coupons;
use App\Models\Orders;
use App\Models\Orders as OrdersModel;
use App\Models\Product;
use App\Models\UtmModel;
use App\Models\WebhookLog;
use App\Services\EcwidService;
use Carbon\Carbon;
use GuzzleHttp\Psr7\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use phpDocumentor\Reflection\Types\Self_;

class OrderService
{
    private $amoCrmService;
    private $shop_select_name = 'Сайт витрина';

    public function __construct()
    {
        $this->amoCrmService = new AmoCrmServise();
    }

    private function getShopAmoProducts($orderData)
    {
        $select_name = $this->shop_select_name;
        if (isset($orderData['order_data']['products']) && !empty($orderData['order_data']['products'])) {
            $products = $orderData['order_data']['products'];
            foreach ($products as &$item)
            {
                if (isset($item['name']['ru'])) {
                    $name = $item['name']['ru'];
                } else {
                    $name = $item['name']['en'];
                }
                $data = [
                    'name' => $name,
                    'sku' => $item['sku'],
                    'price' => $item['price']
                ];

                $product_amo = $this->amoCrmService->getCatalogElementBuSku($item['sku'], $select_name);
                if (!$product_amo) {
                    $product_amo = $this->amoCrmService->setCatalogElement($data, $select_name);
                }

                $customFields = $product_amo->getCustomFieldsValues();
                $fieldPrice = $customFields->getBy('fieldCode', 'PRICE');
                $price_amo = $fieldPrice->getValues()->first()->value;

                if ($name != $product_amo->name || $item['price'] != $price_amo) {
                    $product_amo = $this->amoCrmService->updateCatalogElement($product_amo, $data, $select_name);
                }

                $item['amo_model'] = $product_amo;
            }
        } else {
            return false;
        }

        return $products;
    }

    public function sendInvoice(Orders $order, $orderEcwid) : array
    {

        if ($order->invoiceStatus == 0 && $order->paymentStatus == 4) {

            try {
                $invoiceDada = EcwidService::getDataToGreenInvoice($orderEcwid);
            } catch (\Exception $e) {
                AppErrors::addError("error invoice Data to " . $order->ecwiId, $orderEcwid);
            }

            $invoice = new GreenInvoiceService();

            // настройки аккаунта для инвойса
            // для PayPal
            if ($order->paymentMethod == 3) {

                $dataJson = Storage::disk('local')->get('data/app-setting.json');
                $settingData = json_decode($dataJson, true);

                $invoice = $invoice->setMode($settingData['invoice_mode_paypal']);
            }


            try {
                $res = $invoice->newDoc($invoiceDada);

                if (isset($res['errorCode'])) {
                    AppErrors::addError("invoice create error to " . $order->ecwiId, json_encode($res));

                } else {
                    $data[invoiceStatus] = 1;
                    $data[invoiceData] = json_encode($res);
                }

            } catch (\Exception $e) {
                AppErrors::addError("error invoice newDoc to " . $order->ecwiId, $invoiceDada);
            }

            return $data;
        }
    }




    public static function getShopAmoNotes($data)
    {
        $ordersNotes = 'Детали заказа: #' . $data['order_id'];

        $items = $data['order_data']['products'];
        foreach ($items as $key => $item) {
            $product_name = $item['name']['ru'];

            if (!empty($item['options'])) {
                foreach ($item['options'] as $option) {
                    $name = $option['name']['en'];
                    $value = $option['value']['textTranslated']['en'];
                    $product_name .= " $name $value";
                }
            }

            $ordersNotes .= "\n" . $item['count'] . "x - {$item['price']} шек " . $product_name . ' ';
        }


        if (isset($data['order_data']['products_total'])) {
            $ordersNotes .= "\n ---------------------- \n Итого: {$data['order_data']['products_total']} шек (без скидки)";
        }
        $ordersNotes .= "\n ---------------------- \n";
        $ordersNotes .= "способ оплаты - {$data['paymentMethod']} \n ---------------------- \n";

        if (isset($data['otherPerson'])) {
            if (empty($data['otherPerson'])) {
                $data['otherPerson'] = 'неизвестно';
            }
            $ordersNotes .= "\n ---------------------- \n
            Доставка в подарок: для {$data['nameOtherPerson']} tel {$data['phoneOtherPerson']}
            \n ---------------------- \n";
        }

        if (isset($data['date']) && isset($data['time'])) {

            $timeDelivery = $data['date'] . ' время ' . $data['time'];
        } else {
            $timeDelivery = '';
        }

        $shipping = '';
        if (isset($data['delivery'])) {
            if ($data['delivery'] == 'pickup') {
                $shipping = 'Самовывоз ' . $timeDelivery . "\n ---------------------- \n";

            } else {
                $address = $data['city']
                    . ' ' . $data['street']
                    . ' ' . $data['house'];

                if (!empty($data['flat'])) {
                    $address .= ' ' . $data['flat'];
                }
                if (!empty($data['floor'])) {
                    $address .= ' эт-' . $data['floor'];
                }

                $shipping = 'Доставка: '
                    . "\n Адрес - " . $address
                    . "\n дата - " . $timeDelivery
                    . "\n стоимость - " . $data['order_data']['delivery_price'] . 'шек'
                    . "\n ---------------------- \n";

            }

        }



        if (!empty($data['order_data']['discount'])) {
            $code = $data['order_data']['discount']['code'];
            $discount = "скидка {$data['order_data']['discount']['text']} coupon - $code  \n";
        } else {
            $discount = '';
        }
        if (!empty($data['order_data']['tips'])) {
            $tips = "Чаевые {$data['order_data']['tips']} \n";
        } else {
            $tips = '';
        }

        if(isset($data['client_comment'])) {
            $orderComments = $data['client_comment'];
        } else {
            $orderComments = '';
        }


        if (!empty($orderComments)) {
            $orderComments = 'Комментарий покупателя: ' . "\n"
                . $orderComments . "\n ---------------------- \n";
        } else {
            $orderComments = 'Комментарий покупателя: ' . "\n"
                . "Нет комментария " . "\n ---------------------- \n";
        }

        $notes = $ordersNotes . $orderComments . $discount . $tips . $shipping;

        $notes = $notes . "\n                    Итого: {$data['order_data']['order_total']} шек";

        return $notes;
    }


    public static function getShopAmoDataLead($data)
    {

        // формируем массив данных для амо
        $pipelineId = '4651807'; // воронка
        $statusId = '43924885'; // статус

        if (isset($data['option']['delivery_variant'])) {
            if (preg_match('/Boxit/', $data['option']['delivery_variant'])) {
                $statusId = '4651807'; // статус
            }
        }


        $items = $data['order_data']['products'];
        foreach ($items as $key => $item) {
            $product_name = $item['name']['ru'];

            if (!empty($item['options'])) {
                foreach ($item['options'] as $option) {
                    $name = $option['name']['en'];
                    $value = $option['value']['textTranslated']['en'];
                    $product_name .= " $name $value";
                }
            }

            $tags[] = $product_name;
        }

        if ($data['paymentMethod'] == 'Сash payment') {
            $payment = 'Оплата наличными по факту';
        } elseif ($data['paymentStatus'] == 'PAID') {
            $payment = 'Оплачен';
        } elseif ($data['paymentMethod'] == 'Bit') {
            $payment = 'Ожидает оплату по Bit';
        } else {
        }

        // deliwery adress
        $address = '';
        if (isset($data['delivery'])) {
            if ($data['delivery'] == 'pickup') {
                $address = 'Самовывоз';
            } elseif ($data['delivery'] == 'delivery') {
                $address = $data['city']
                    . ' ' . $data['street']
                    . ' ' . $data['house'];
            }
        }




        if ($data['lang'] == 'ru') {
            $lang = 'Русский';
        } elseif ($data['lang'] == 'en') {
            $lang = 'Английский';
        } else {
            $lang = 'Иврит';
        }
        $tags[] = $lang;
        if (isset($data['time'])) {
            if ($data['time'] == 'Время доставки') {
                $data['time'] = '11:00-14:00';
            }

        } else {
            $data['time'] = '11:00-14:00';
        }

        $timeDelivery = $data['time'];

        if (!isset($data['date'])) {
            $date = new Carbon();
            $data['date'] = $date->format('Y-m-d');
        }

        $delivery_time = $data['time'];
        $time = str_replace(':00', '', $delivery_time);
        $time = str_replace('-', ':', $time);
        $delivery_date_time = $data['date'] . ' ' . $time . ':00 +0000';
        $date = Carbon::parse($delivery_date_time);
        $dateOrder = strtotime($date->format('Y-m-d H:i:s'));


        if (!isset($data['client_comment'])) {
            $data['client_comment'] = '';
        }

        $phone = false;
        if (isset($data['phone'])) {
            $phone = self::phoneAmoFormater($data['phone']);
        }

        $dataOrderAmo = [
            'order name'  => '#' . $data['order_id'],
            'order_id'    => $data['order_id'],
            'api_mode'    => 'ShopTB',
            'order price' => $data['order_data']['order_total'],
            'pipelineId'  => $pipelineId,
            'statusId'    => $statusId,
            'notes'       => $data['client_comment'],
            'lang'        => $lang,
//            'refer_URL'   => $data['option']['refererUrl'],
            'name'        => $data['clientName'],
            'email'       => $data['email'],
            'phone'       => $phone,
            'address'     => $address,
            'payment'     => $payment,
            'date'        => $dateOrder,
            'time'        => $timeDelivery,
            'tags'        => $tags
        ];

        if (!empty($data['flat'])) {
            $dataOrderAmo['room_number'] = $data['flat'];
        }


        if (!empty($data['floor'])) {
            $dataOrderAmo['floor'] = $data['floor'];
        }

        if (isset($data['otherPerson'])) {
            if (isset($data['nameOtherPerson'])) {
                $dataOrderAmo['to_presents']['presents_name'] = $data['nameOtherPerson'];
            }
            if (isset($data['phoneOtherPerson'])) {
                $dataOrderAmo['to_presents']['presents_phone'] = $data['phoneOtherPerson'];
            }

        }


        $utmData = UtmModel::where('order_id', $data['order_id'])->first();
        if ($utmData) {
            $utmData = $utmData->toArray();
            unset($utmData['id']);
            unset($utmData['order_id']);
            unset($utmData['created_at']);
            unset($utmData['updated_at']);
            $dataOrderAmo['utmData'] = $utmData;
        }

        return $dataOrderAmo;
    }



    // подготовка запроса для получения урл
    public function getIcreditDataOrder($data)
    {
        /////////////////////////////////////////////////////
        // construct order to icredit
        $total = 0;
        $discount = 0;

        if ($data['Cart']['discount'] != 'false') {
           $discount = $data['Cart']['discount']['total_discount'];
        }

        $items = $data['Cart']['items'];
        foreach ($items as $key => $item) {
            $varieble_id = $item['variable_id'];
            $product_name = $item['name'];

            if ($varieble_id > 0 ) {
                $product_name .= ' ' . $item['option']['name'] . $item['option']['value'];
            }


            $orderItems[$key]['CatalogNumber'] = $item['sku'];
            $orderItems[$key]['Quantity'] = $item['count'];
            $orderItems[$key]['UnitPrice'] = $item['price'];
            $orderItems[$key]['Description'] = $product_name;

            $total = $total + $item['price'] * $item['count'];
        }

        if ($discount > 0) {
            $total -= $discount;
        }


        if ($data['option']['delivery_price'] > 0) {

            $orderItems[++$key]['CatalogNumber'] = 'delivery';
            $orderItems[$key]['Quantity'] = 1;
            $orderItems[$key]['UnitPrice'] = (int) $data['option']['delivery_price'];
            $orderItems[$key]['Description'] = 'delivery';

            $total = $total + (int) $data['option']['delivery_price'];
        }

        if ($data['option']['tips_price'] > 0) {

            $tips = (int) $data['option']['tips_price'] / 100;
            $tips_value = round($total * $tips, 2);

            $orderItems[++$key]['CatalogNumber'] = 'tips_' . $data['option']['tips_price'];
            $orderItems[$key]['Quantity'] = 1;
            $orderItems[$key]['UnitPrice'] = $tips_value;
            $orderItems[$key]['Description'] = 'tips ' . $data['option']['tips_price'] . '%';

            $total = $total + $tips_value;
        }

        if ($data['option']['lang'] != 'he') {
            $data['option']['lang'] = 'en';
        }

        $order['lang']     = $data['option']['lang'];
        $order['items']    = $orderItems;
        $order['orderId']  = $data['Cart']['order_id'];
        $order['custom2']  = 'ServerTB';
        $order['email']    = $data['Cart']['person']['email'];
        $order["phone"]    = $data['Cart']['person']["phone"];
        $order["name"]     = $data['Cart']['person']["name"];
        $order["discount"] = $discount;

//        print_r("total - $total");
//        dd($order);
        return $order;
    }

    public static function getOrderDataToGinvoice($data)
    {
//        print_r($data);
        $lang = 'he';
        $dateObj = new Carbon();
        $date = $dateObj->format('Y-m-d');

        $orderData['email'] = $data['Cart']['person']['email'];

        $name = trim($data['Cart']['person']['name']);

        $name = AppServise::TransLit($name);
        $orderData['name'] = $name;
        $orderData['lang'] = $data['option']['lang'];
        $orderData['phone'] = $data['Cart']['person']['phone'];
        $orderData['city'] = '';
        $orderData['address'] = '';

        if ($data['option']['delivery_method'] == 'delivery') {
            $orderData['city'] = AppServise::TransLit($data['Cart']['person']['address']['city']);
            $orderData['address'] = AppServise::TransLit($data['Cart']['person']['address']['street']);
        }


        $orderData['remarks'] = $data['Cart']['order_id'] . " פרטים - מספר הזמנה: " ;
        $orderData['orderNames'] =  $data['Cart']['order_id'] . " מספר הזמנה: ";

        foreach ($data['Cart']['items'] as $item) {
            $product_id = $item['id'];
            $variable_id = $item['variable_id'];

            $product_name = $item['name'];

            if ($variable_id > 0 ) {
                $product_name .= ' ' . $item['option']['name'] . '-' . $item['option']['value'];
            }


            $items[] =  [
                "catalogNum"   => $item['sku'],
                "description"  => $product_name,
                "quantity"     => $item['count'],
                "price"        => $item['price'],
                "currency"     => "ILS",
                "currencyRate" => 1,
                "vatType"      => 0
            ];

            $total = $item['count'] * $item['price'];
            $orderData['remarks'] .= "\n ILS $total = {$item['count']} x ILS {$item['price']} : $product_name";
            $orderData['orderNames'] .= "\n $product_name ({$item['count']}) ";
        }

        if ($data['Cart']['discount'] != 'false') {
            $discount = $data['Cart']['discount'];
            $orderData['remarks'] .= "\n ILS -{$discount['total_discount']} :discount {$discount['display']}";
        }

        $orderData['items'] = $items;

        if ($data['option']['delivery_method'] == 'delivery') {
            // стоимоссть доставки
            $orderData['delivery'] = "\n delivery:\n ILS {$data['option']['delivery_price']} ............... " ;
        }


            if ($data['option']['tips_price'] > 0) {

                $orderData['tips'] = "\n ___________________\n טיפים: "
                    . $data['option']['tips_price'] . "%\n"
                    . 'ILS '  . $data['option']['tips_value']
                    ." ...........";
            }



        if (isset($data['externalTransactionId'])) {
            $orderData['payId'] = $data['externalTransactionId'];
        } else {
            $orderData['payId'] = '';
        }

        $orderData['total'] = $data['option']['total_price'];
        $orderData['payDate'] = $date;


        if ($data['option']['payment_method'] == 'Credit card' || $data['option']['payment_method'] == 'PayPal') {
            $orderData['type'] = 3;

            if ($data['option']['payment_method'] == 'Credit card') {
                $orderData['bankName'] = 'iCredit';
            } elseif ($data['option']['payment_method'] == 'PayPal') {
                $orderData['bankName'] = 'PayPal';
            }
        } else {
            $orderData['type'] = 1;
            $orderData['bankName'] = 'none';
        }

        return $orderData;
    }

    public static function getShopOrderDataToGinvoice(Orders $order)
    {
        $order_data = $order->toArray();
        $data = json_decode($order_data['orderData'], true);
        $lang = 'he';
        $dateObj = new Carbon();
        $date = $dateObj->format('Y-m-d');

        $orderData['email'] = $data['email'];

        $name = trim($data['clientName']);

        $name = AppServise::TransLit($name);
        $orderData['name'] = $name;
        $orderData['lang'] = $data['lang'];
        if (!empty($data['phone']))
        $orderData['phone'] = $data['phone'];
        $orderData['city'] = '';
        $orderData['address'] = '';

        if (!empty($data['city'])) {
            $orderData['city'] = AppServise::TransLit($data['city']);
            $orderData['address'] = AppServise::TransLit($data['street'] . ' ' . $data['house']);
        }


        $orderData['remarks'] = $order_data['order_id'] . " פרטים - מספר הזמנה: " ;
        $orderData['orderNames'] =  $order_data['order_id'] . " מספר הזמנה: ";


        foreach ($data['order_data']['products'] as $item) {

            $product_name = $item['name']['en'];

            if (!empty($item['options'])) {
                foreach ($item['options'] as $option) {
                    $name = $option['name']['en'];
                    $value = $option['value']['textTranslated']['en'];
                    $product_name .= " $name $value";
                }
            }


            $items[] =  [
                "catalogNum"   => $item['sku'],
                "description"  => $product_name,
                "quantity"     => $item['count'],
                "price"        => $item['price'],
                "currency"     => "ILS",
                "currencyRate" => 1,
                "vatType"      => 0
            ];

            $total = $item['count'] * $item['price'];
            $orderData['remarks'] .= "\n ILS $total = {$item['count']} x ILS {$item['price']} : $product_name";
            $orderData['orderNames'] .= "\n $product_name ({$item['count']}) ";
        }

        if (isset($data['order_data']['discount'])) {
            $discount = $data['order_data']['discount'];
            $orderData['remarks'] .= "\n ILS -{$discount['value']} :discount ";
        }



        $orderData['items'] = $items;

        if (isset($data['delivery'])) {
            if ($data['delivery'] == 'pickup') {
                if (!empty($data['order_data']['delivery_discount'])) {
                    $discount = $data['order_data']['delivery_discount'];
                    $orderData['remarks'] .= "\n ILS - {$discount} :pickup discount ";
                }

            } else {
                // стоимоссть доставки
                $orderData['delivery'] = "\n delivery:\n ILS {$data['order_data']['delivery_price']} ............... " ;
            }
        }



        if (isset($data['order_data']['tips']) && $data['order_data']['tips'] > 0) {

            $orderData['tips'] = "\n ___________________\n טיפים: "
                . $data['premium'] . "%\n"
                . 'ILS '  . $data['order_data']['tips']
                ." ...........";
        }



        if (isset($data['externalTransactionId'])) {
            $orderData['payId'] = $data['externalTransactionId'];
        } else {
            $orderData['payId'] = '';
        }

        $orderData['total'] = $data['order_data']['order_total'];
        $orderData['payDate'] = $date;



        if ($data['methodPay'] == 1 || $data['methodPay'] == 3) {
            $orderData['type'] = 3;

            if ($data['methodPay'] == 1) {
                $orderData['bankName'] = 'iCredit';
            } elseif ($data['methodPay'] == 3) {
                $orderData['bankName'] = 'PayPal';
            }

        } else {
            $orderData['type'] = 1;
            $orderData['bankName'] = 'none';
        }

        return $orderData;
    }


    public static function getShopOrderData($order)
    {

        $products = $order['order_data']['products'];

        $products_total = 0;
        foreach ($products as &$item) {
            $id = $item['id'];
            $product = Product::where('id', $id)->first();
            $translate = json_decode($product->translate, true);

            if (isset($item['variant'])) {
                $var_key = $item['variant'];
                $variables = json_decode($product->variables, true);
                $variant = $variables[$var_key];
                $price = $variant['defaultDisplayedPrice'];

                $item_total = $price * $item['count'];

                $item['price'] = $variant['defaultDisplayedPrice'];
                $item['sku'] = $variant['sku'];

            } else {
                $item_total = $product->price * $item['count'];
                $item['price'] = $product->price;
                $item['sku'] = $product->sku;
            }
            $item['name'] = $translate['nameTranslated'];

            if (isset($item['options'])) {
                $options = json_decode($product->options, true);

                foreach ($item['options'] as &$item_option) {
                    $option_key = $item_option['key'];
                    $option_choice_key = $item_option['value'];
                    $option = $options[$option_key];
                    $choice = $option['choices'][$option_choice_key];
                    if ($choice['priceModifier'] != 0) {
                        if ($choice['priceModifierType'] == 'ABSOLUTE') {
                            $price =  $product->price + $choice['priceModifier'] / 1;
                        } else {
                            $price =  $product->price + ($product->price / 100 * $choice['priceModifier']);
                        }
                        $item['price'] = $price;
                        $item_total = $price * $item['count'];
                        $item['total'] = $item_total;
                    }
                    $item_option['name'] = $option['nameTranslated'];
                    $item_option['value'] = $option['choices'][$option_choice_key];
                }
            }

            $products_total += $item_total;
        }
        $data['products'] = $products;
        $order_total = $products_total;

        // promo code
        if (!empty($order['order_data']['promo_code'])) {
            $code = $order['order_data']['promo_code'];
            $coupon = Coupons::where('code', $code)->first();

            if (!empty($coupon) && $coupon->status == 'active') {
                $data['discount']['code'] = $code;

                $discount = json_decode($coupon->discount, true);
                $value = $discount['value'];
                if ($discount['mod'] == 'PERSENT') {
                    $disc = $order_total / 100 * $value;
                    $disc = round($disc,1);
                    $data['discount']['text'] = "$value% - $disc";
                    $data['discount']['value'] = $disc;
                    $order_total -= $disc;
                } else {
                    $data['discount']['text'] = "$value";
                    $data['discount']['value'] = $value;
                    $order_total -= $value;
                }
            }
        }

        // проверяем чаевые
        if (!empty($order['premium'])) {
            $tips = $order_total  / 100 * $order['premium'];
            $tips = round($tips, 1);
            $data['tips'] = $tips;
            $order_total += $tips;
        }

        // delivery
        if ($order['delivery'] != 'pickup') {
            $data['delivery_price'] = (int) $order['order_data']['delivery_price'];
            $order_total += (int) $order['order_data']['delivery_price'];
        } else {
            $data['delivery_discount'] = round($order_total / 100 * $order['order_data']['delivery_discount'], 1);
            $order_total -= $data['delivery_discount'];
        }


        $data['items'] = $products;
        $data['products_total'] = round($products_total, 1);
        $data['order_total'] = round($order_total, 1);
        $order['order_data'] = $data;

        return $order;
    }

    public static function getShopIcreditOrderData($order)
    {
        $order->orderData = json_decode($order->orderData, true);
        $orderData = $order->toArray();
        $data = $orderData['orderData']['order_data'];

        /////////////////////////////////////////////////////
        // construct order to icredit
        $total = $orderData['orderPrice'];
        $order_lang = $orderData['orderData']['lang'];
        $discount = 0;

        if (isset($data['delivery_discount'])) {
            $discount += $data['delivery_discount'];
        }
        if (isset($data['discount'])) {
            $discount += $data['discount']['value'];
        }

        $items = $data['products'];
        foreach ($items as $item) {
            $product_name = $item['name']['en'];

            $orderItems[] = [
                'CatalogNumber' => $item['sku'],
                'Quantity' => $item['count'],
                'UnitPrice' => $item['price'],
                'Description' => $product_name
            ];

        }

        if (isset($data['delivery_price'])) {
            $orderItems[] = [
                'CatalogNumber' => 'delivery',
                'Quantity' => 1,
                'UnitPrice' => $data['delivery_price'],
                'Description' => 'delivery'
            ];
        }

        if ($orderData['orderData']['premium'] > 0) {

            $orderItems[] = [
                'CatalogNumber' => 'tips_' . $orderData['orderData']['premium'],
                'Quantity' => 1,
                'UnitPrice' => $data['tips'],
                'Description' => 'tips ' . $orderData['orderData']['premium'] . '%'
            ];

        }


        if ($order_lang != 'he') {
            $order_lang = 'en';
        }

        $order_data['lang']     = $order_lang;
        $order_data['items']    = $orderItems;
        $order_data['orderId']  = $order->order_id;
        $order_data['custom2']  = 'ShopTB';
        $order_data['email']    = $orderData['orderData']['email'];
        if (isset($orderData['orderData']["phone"])) {
            $order_data["phone"]    = $orderData['orderData']["phone"];
        }
        $order_data["name"]     = $orderData['orderData']["clientName"];
        $order_data["discount"] = $discount;

        return $order_data;
    }

    public function createOrderToAmocrm($order_id)
    {

        if ($order_id) {

            $paymentMetods = AppServise::getOrderPaymentMethod();
            $paymentStatuses = AppServise::getOrderPaymentStatus();
            $lang_values = AppServise::getLangs();
            $amoCrmService = $this->amoCrmService;

            WebhookLog::addLog('new amo order ', $order_id);

            $orderService = $this;
            $order = Orders::where('order_id', $order_id)->first();
            $orderData = json_decode($order['orderData'], true);
            $orderData['paymentMethod'] = $paymentMetods[$order['paymentMethod']];
            $orderData['paymentStatus'] = $paymentStatuses[$order['paymentStatus']];
            $orderData['order_id'] = $order->order_id;
            $order_lang =  $orderData['lang'];
            $amo_lang = $lang_values[$order_lang]['name_ru'];

            // проверка клиента
            $client_id = $order->clientId;
            $client = Clients::where('id', $client_id)->first();
            $amo_contact = $this->searchOrCreateAmoContact($client, $amo_lang);

            if ($amo_contact->id != $client->amoId) {
                $client->amoId = $amo_contact->id;
                $client->save();
            }

            $amoData = $orderService::getShopAmoDataLead($orderData);
            $amoNotes = $orderService::getShopAmoNotes($orderData);
            $amoData['text_note'] = $amoNotes;


            $open_lead = $amoCrmService->searchOpenLeadByContactId($client->amoId);
            if ($open_lead) {
                $lead = $amoCrmService->updateLead($open_lead, $amoData);
            } else {
                $lead = $amoCrmService->createNewLead($amoData);
            }

            if ($lead) {
                $amoCrmService->addContactToLead($amo_contact, $lead);
                $amoCrmService->addTextNotesToLead($lead->id, $amoNotes);

                $amoProducts = self::getShopAmoProducts($orderData);
                $amoCrmService->addSopProductsToLead($lead->id, $amoProducts);

                $amo_invoice_id = $amoCrmService->addInvoiceToLead($amo_contact->id, $order->order_id, $lead->id, (float) $order->orderPrice, $order->paymentStatus);
                $amoData['invoice_id'] = $amo_invoice_id;


                $order->amoData = json_encode($amoData);
                $order->amoId =$lead->id;
                $order->save();
            } else {

                AppErrors::addError('error create amo lead', $amoData);
                return false;
            }

            return true;
        }
    }

    public function createOrderToAmocrmNew($order_id)
    {

        if ($order_id) {


            $paymentMetods = AppServise::getOrderPaymentMethod();
            $paymentStatuses = AppServise::getOrderPaymentStatus();
            $lang_values = AppServise::getLangs();
            $amoCrmService = $this->amoCrmService;

            WebhookLog::addLog('new amo order ', $order_id);

            $orderService = $this;
            $order = Orders::where('order_id', $order_id)->first();
            $orderData = json_decode($order['orderData'], true);
            $orderData['paymentMethod'] = $paymentMetods[$order['paymentMethod']];
            $orderData['paymentStatus'] = $paymentStatuses[$order['paymentStatus']];
            $orderData['order_id'] = $order->order_id;
            $order_lang =  $orderData['lang'];
            $amo_lang = $lang_values[$order_lang]['name_ru'];

            // проверка клиента
            $client_id = $order->clientId;
            $client = Clients::where('id', $client_id)->first();
            $amo_contact = $this->searchOrCreateAmoContact($client, $amo_lang);

            if ($amo_contact->id != $client->amoId) {
                $client->amoId = $amo_contact->id;
                $client->save();
            }

            $amoData = $orderService::getShopAmoDataLead($orderData);
            $amoNotes = $orderService::getShopAmoNotes($orderData);
            $amoData['text_note'] = $amoNotes;


            $open_lead = $amoCrmService->searchOpenLeadByContactId($client->amoId);

            if ($open_lead) {
                $lead = $amoCrmService->updateLead($open_lead, $amoData);

            } else {

                $lead = $amoCrmService->createNewLead($amoData);
                $amoCrmService->addContactToLead($amo_contact, $lead);
            }

            if ($lead) {
                $amoCrmService->addTextNotesToLead($lead->id, $amoNotes);

                $amoProducts = self::getShopAmoProducts($orderData);
                $amoCrmService->addSopProductsToLead($lead->id, $amoProducts);

                $amo_invoice_id = $amoCrmService->addInvoiceToLead($amo_contact->id, $order->order_id, $lead->id, (float) $order->orderPrice, $order->paymentStatus);
                $amoData['invoice_id'] = $amo_invoice_id;


                $order->amoData = json_encode($amoData);
                $order->amoId =$lead->id;
                $order->save();
            } else {

                AppErrors::addError('error create amo lead', $amoData);
                return false;
            }

            return true;
        }
    }

    public function searchOrCreateAmoContact(Clients $client, $lang)
    {
        $amoCrmService = $this->amoCrmService;
        $phone = self::phoneAmoFormater($client->phone);
        $contactData = [
            'name' => $client->name,
            'phone' => $phone,
            'email' => $client['email'],
            'lang' => $lang
        ];

        $client_data = json_decode($client->data, true);
        if (isset($client_data['clientBirthDay'])) {
            $date = AppServise::dateFormater($client_data['clientBirthDay']);
            if ($date) {
                $date = new Carbon($date);
                $date_time = strtotime($date->format('Y-m-d H:i:s'));
                $contactData['birthday'] = $date_time;
            }
        }

        if (!empty($client->amoId)) {
            $contact = $amoCrmService->getContactBuId($client->amoId);
        } else {
            $contact = $this->searchAmoContact($client);
        }

        if (!$contact) {
            $contact = $this->searchAmoContact($client);
        }
        if (!$contact) {
            $contact = $amoCrmService->createContact($contactData);
        } else {
            $contact = $amoCrmService->syncContactData($contact, $contactData);
        }

        return $contact;
    }


    public function searchAmoContact(Clients $client)
    {
        $amoCrmService = $this->amoCrmService;

        $contact = $amoCrmService->searchContactFilter($client->email);

        if (!$contact) {
            $contact = $amoCrmService->searchContactFilter($client->phone);
        }
        if (!$contact) {
            $clientData = json_decode($client->data, true);
            if (isset($clientData['phones'])) {
                foreach ($clientData['phones'] as $phone) {
                    if (!$contact) {
                        $contact = $amoCrmService->searchContactFilter($phone);
                    }
                    if (!$contact) {
                        $contact = $amoCrmService->searchContactFilter(self::phoneAmoFormater($phone));
                    }
                }
            }

        }

        return $contact;
    }

    public static function phoneAmoFormater($phone)
    {
        $phone_or = $phone;

        $phone = str_replace(' ', '', $phone);
        $phone = str_replace('-', '', $phone);

        if (preg_match('/972/', $phone)) {
            $phone = str_replace('9720', '972', $phone);
        }

        if (preg_match('/^(\+[0-9]{3})([0-9]{3})([0-9]{3})([0-9]{4})$/', $phone, $mathes)) {

            $phone = $mathes[1].' '.$mathes[2].'-'.$mathes[3].'-'.$mathes[4];
        } elseif (preg_match('/^(\+[0-9]{3})([0-9]{2})([0-9]{3})([0-9]{4})$/', $phone, $mathes)) {

            $phone = $mathes[1].' '.$mathes[2].'-'.$mathes[3].'-'.$mathes[4];
        } else {
            $phone = $phone_or;
        }

        return $phone;
    }

    public static function sendMailNewOrder($order_id, $mode)
    {
        $paymentStatuses = [
            '4' => [
                'ru' => 'Оплачен',
                'en' => 'Paid',
                'he' => 'Paid'
            ],
            '3' => [
                'ru' => 'Ожидает оплаты',
                'en' => 'Awaiting payment',
                'he' => 'Awaiting payment'
            ],
            '2' => [
                'ru' => 'Ожидает оплаты',
                'en' => 'Awaiting payment',
                'he' => 'Awaiting payment'
            ]
        ];
        $paymentMethods = AppServise::getOrderPaymentMethod();

        $order = OrdersModel::where('order_id', $order_id)->first();
        $client_id = $order->clientId;
        $client = Clients::where('id', $client_id)->first();


        $orderData = json_decode($order->orderData, true);
        $lang = $orderData['lang'];


        if ($client->email == 'test@mail.ru') {
            $client->email = 'virikidorhom@gmail.com';
            $orderData['email'] = $client->email;
        }
        if ($mode == 'test_send' || $mode == 'test_view') {
            $client->email = 'virikidorhom@gmail.com';
            $orderData['email'] = $client->email;
        }

        foreach ($orderData['order_data']['products'] as &$item) {
            $product_image = Product::where('id', $item['id'])->value('image');
            if (isset($product_image)) {
                $product_image = json_decode($product_image, true);
                $product_image = $product_image['image160pxUrl'];
                $item['img_url'] = $product_image;
            }


            $opt_str = '';
            if (isset($item['options'])) {
                foreach ($item['options'] as $option) {
                    if (!empty($option['name'][$lang])) {
                        $name = $option['name'][$lang];
                    } else {
                        $name = $option['name']['en'];
                    }
                    if (!empty($option['value']['textTranslated'][$lang])) {
                        $value = $option['value']['textTranslated'][$lang];
                    } else {
                        $value = $option['value']['textTranslated']['en'];
                    }
                    $opt_str = " / $name $value";

                }
            }

            $item['info'] = $opt_str;
        }

        $order->orderData = $orderData;

        $order->paymentMethod = $paymentMethods[$order->paymentMethod];
        $order->paymentStatus = $paymentStatuses[$order->paymentStatus][$lang];

        try {
            if ($mode == 'test_send' || $mode == 'send') {
                if ($mode == 'test_send') {
                    print_r('send mail to ' . $client->email);
                }
                Mail::to($client)->send(new NewOrder($order));
            }
            return $order;
        } catch (\Exception $e) {
            if ($mode == 'test_send' || $mode == 'test_view') {
                dd($e);
            }
            AppErrors::addError("error isend mail to", $order_id);
            return false;
        }
    }

    public function changeProductsCount($order)
    {
        $orderData = json_decode($order->orderData, true);
        $products = $orderData['order_data']['products'];
        foreach ($products as $item) {
            $prod_id = $item['id'];
            $product = Product::find($prod_id);
            if ($product) {
                if (empty($item['variant'])) {
                    if ($product->unlimited == 0) {
                        $product->count -= $item['count'];
                        if ( $product->count < 0) {
                            $product->count = 0;
                        }
                        $product->save();
                    }
                } else {
                    $variant_key = $item['variant'];
                    $product_variables = json_decode($product->variables, true);

                    if ($product_variables[$variant_key]['unlimited'] == 0) {
                        $product_variables[$variant_key]['quantity'] -= $item['count'];
                        if ($product_variables[$variant_key]['quantity'] < 0) {
                            $product_variables[$variant_key]['quantity'] = 0;
                        }
                        $product->variables = json_encode($product_variables);
                        $product->save();
                    }

                }
            }

        }

    }

    public function changeProductsCountTest($order)
    {
        $orderData = json_decode($order->orderData, true);
        $products = $orderData['order_data']['products'];
//        dd($products);
        foreach ($products as $item) {
            $prod_id = $item['id'];
            $product = Product::find($prod_id);
            if ($product) {
                if (empty($item['variant'])) {
                    if ($product->unlimited == 0) {
                        $product->count -= $item['count'];
                        if ( $product->count < 0) {
                            $product->count = 0;
                        }
                        $product->save();
                    }
                } else {
                    $variant_key = $item['variant'];
                    $product_variables = json_decode($product->variables, true);

                    if ($product_variables[$variant_key]['unlimited'] == 0) {
                        $product_variables[$variant_key]['quantity'] -= $item['count'];
                        if ($product_variables[$variant_key]['quantity'] < 0) {
                            $product_variables[$variant_key]['quantity'] = 0;
                        }
                        $product->variables = json_encode($product_variables);
                        $product->save();
                    }

                }
            }

        }

    }

}