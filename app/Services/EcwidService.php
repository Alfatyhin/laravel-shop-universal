<?php


namespace App\Services;


use App\Models\AppErrors;
use App\Models\WebhookLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;

class EcwidService
{
    private $secret_key;
    private $shop_id;
    private $secret_token;
    private $data;
    private static $payModyle = 'CUSTOM_PAYMENT_APP-custom-app-48198100-1';
    private $category_set_id = '82971920';
    private $category_in_stock_id = '109788705';
    private $always_write_off = ['123457253'=> true, '123633263' => true];

    public function __construct()
    {
        $this->secret_key = $_ENV['ECWID_APP_SECRET'];
        $this->shop_id = $_ENV['ECWID_SHOP_ID'];
        $this->secret_token = $_ENV['ECWID_SECRET_TOKEN'];
    }


    public function decoder($data)
    {
        $secret_key = $this->secret_key;
        // Get the encryption key (16 first bytes of the app's client_secret key)
        $encryption_key = substr($secret_key, 0, 16);

        // Decrypt payload
        $json_data = EcwidService::aes_128_decrypt($encryption_key, $data);

        // Decode json
        $json_decoded = json_decode($json_data, true);

        return $json_decoded;
    }

    private static function aes_128_decrypt($key, $data) {
        // Ecwid sends data in url-safe base64. Convert the raw data to the original base64 first
        $base64_original = str_replace(array('-', '_'), array('+', '/'), $data);

        // Get binary data
        $decoded = base64_decode($base64_original);

        // Initialization vector is the first 16 bytes of the received data
        $iv = substr($decoded, 0, 16);

        // The payload itself is is the rest of the received data
        $payload = substr($decoded, 16);

        // Decrypt raw binary payload
        $json = openssl_decrypt($payload, "aes-128-cbc", $key, OPENSSL_RAW_DATA, $iv);
        //$json = mcrypt_decrypt(MCRYPT_RIJNDAEL_128, $key, $payload, MCRYPT_MODE_CBC, $iv); // You can use this instead of openssl_decrupt, if mcrypt is enabled in your system

        return $json;
    }

    public function getHeaderHash($data)
    {
        $eventCreated = $data['eventCreated'];
        $eventId = $data['eventId'];
        $res = hash_hmac("sha256",  "$eventCreated.$eventId", $this->secret_key, true);
        $res = base64_encode($res);

        return $res;
    }



    public function getAbondoneBascets($showHidden, $dateFrom, $dateTo)
    {

        $shopId = $this->shop_id;
        $token = $this->secret_token;
        $url = "https://app.ecwid.com/api/v3/$shopId/carts?token=$token&showHidden=$showHidden&createdFrom=$dateFrom&createdTo=$dateTo";
        $res = $this->getQuest($url);

        var_dump($url);
        return $res;

    }

    public function inStockUpdate($product)
    {
//        var_dump($product);

        if ($product['defaultCategoryId'] == 105517392) {
            die;
        }

        $inStockProducts = $this->getProductsByCategoryId($this->category_in_stock_id);

        foreach ($inStockProducts['items'] as $item) {
            $id = $item['id'];
            $inStock[$id] = $id;

        }

        $inStockAdd = false;

        $productId = $product['id'];

        if (empty($inStock[$productId]) && !empty($product['quantity'])) {
            echo "add to stock $productId \n";
            $inStock[$productId] = $productId;
            $inStockAdd = true;
        }

        $variateInStock = false;
        $productVariate = false;
        if (!empty($product['combinations'])) {
            $productVariate = true;

            foreach ($product['combinations'] as $item) {

                $variateId = $item['id'];

                if (!empty($item['quantity'])) {


                    $count = $item['quantity'];
                    $variateInStock = true;
                    echo "count = $count variate to stock $productId variateid - $variateId \n";

                    foreach ($item['options'] as $option) {
                        if ($option['name'] == 'Size') {


                            if (!isset($defaultDisplayedPrice)) {
                                $defaultDisplayedPrice = $item['defaultDisplayedPrice'];
                                $defaultDisplaySize = $option['value'];
                            } else {
                                if ($defaultDisplayedPrice >= $item['defaultDisplayedPrice']) {
                                    $defaultDisplayedPrice = $item['defaultDisplayedPrice'];
                                    $defaultDisplaySize = $option['value'];
                                }
                            }

                            $sizes[] = $option['value'];

                        }
                    }


                } else {


                }

            }

            if ($variateInStock && empty($inStock[$productId])) {
                $inStockAdd = true;
                $inStock[$productId] = $productId;

            }

        }


        if ($productVariate) {

            if (!empty($product['ribbon']['text'])) {

                $str = $product['ribbon']['text'];

                $newStr = preg_replace('/in Stock Size ([MSL& ])*/', '', $str);
                $newStr = trim($newStr);;

                $strRu = $product['ribbonTranslated']['ru'];

                $newStrRu = preg_replace('/В наличии размер ([MSXL& ])*/', '', $strRu);
                $newStrRu = trim($newStrRu);

                $strHe = $product['ribbonTranslated']['he'];

                $newStrHe = preg_replace('/([MSL& ])*גודל זמין/', '', $strHe);
                $newStrHe = trim($newStrHe);

//                $productDataRibbon['ribbon'] = [
//                    "text" => $newStr,
//                    "color" => "#7091DA"
//                ];
//                $productDataRibbon['ribbonTranslated'] = [
//                    'ru' => $newStrRu,
//                    'en' => $newStr,
//                    'he' => $newStrHe,
//                ];

            }
        }



        if (isset($sizes)) {


//            $productDataNew['showOnFrontpage'] = -1;
            // default ddisplay size
            foreach ($product['options'] as $optionKey => $items) {

                if ($items['name'] == 'Size') {

                    foreach ($items['choices'] as $k => $item) {

                        if ($item['text'] == $defaultDisplaySize) {


                            $productDataNew['options'] = $product['options'];
                            $productDataNew['options'][$optionKey]['defaultChoice'] = $k;
                        }

                    }

                }

            }

            $sizeStr = implode(' & ', $sizes);

//            $productDataNew['ribbon'] = [
//                "text" => "in Stock Size " . $sizeStr,
//                "color" => "#7091DA"
//            ];
//            $productDataNew['ribbonTranslated'] = [
//                'ru' => "В наличии размер " . $sizeStr,
//                'en' => "in Stock Size " . $sizeStr,
//                'he' => $sizeStr . " ". "גודל זמין",
//            ];



            if (!empty($product['ribbon']) &&
                ($product['ribbon']['text'] == $productDataNew['ribbon']['text'])) {
            } else {
//                echo "new laibel <hr>";
//                $res = $this->updateProduct($productId, $productDataNew);
//                var_dump($res);
//
//                $webhoock['productId'] = $id;
//                $webhoock['new laibel'] = $productDataNew['ribbon']['text'];
//
//                WebhookLog::addLog('ecwid webhook', $webhoock);
            }


        } else {
            if (!empty($productDataNew['ribbon']['text'])) {
//                $productDataNew['ribbon'] = [
//                    "text" => "",
//                ];
//                $productDataNew['ribbonTranslated'] = [
//                    'ru' => "",
//                    'en' => "",
//                    'he' => "",
//                ];
//                echo "delete laibel <hr>";
//                $res = $this->updateProduct($productId, $productDataNew);
//                var_dump($res);
//
//                $webhoock['productId'] = $id;
//                $webhoock['delete laibel'] = $productDataNew['ribbon']['text'];
//
//                WebhookLog::addLog('ecwid webhook', $webhoock);
            }

        }

        if (!empty($inStock[$productId]) && empty($product['quantity']) && $variateInStock == false) {
            unset($inStock[$productId]);
            $inStockAdd = true;
        }

        if ($inStockAdd) {
            $data['productIds'] = array_values($inStock);
            $res = $this->updateProductsCategory($this->category_in_stock_id, $data);

            $webhoock['productId'] = $id;
            $webhoock['add in stock'] = $id;

            WebhookLog::addLog('ecwid webhook', $webhoock);
        }

    }

    public function updateProductsCategory($categoryId, array $data)
    {
        $categoryData = $this->getCategoryById($categoryId);

        foreach ($data as $key => $value) {
            $categoryData[$key] = $value;
        }

        $shopId = $this->shop_id;
        $token = $this->secret_token;
        $url ="https://app.ecwid.com/api/v3/$shopId/categories/$categoryId?token=$token";
        $res = $this->putQuest($url, $categoryData);

        return $res;
    }

    private function updateProduct($productId, array $data)
    {
        $shopId = $this->shop_id;
        $token = $this->secret_token;
        $url ="https://app.ecwid.com/api/v3/$shopId/products/$productId?token=$token";
        $res = $this->putQuest($url, $data);

        return $res;
    }

    public function getCategories()
    {
        $shopId = $this->shop_id;
        $token = $this->secret_token;
        $url = "https://app.ecwid.com/api/v3/$shopId/categories?token=$token";
        $res = $this->getQuest($url);

        return $res;
    }

    public function getCategoryById($categoryId)
    {
        $shopId = $this->shop_id;
        $token = $this->secret_token;
        $url = "https://app.ecwid.com/api/v3/$shopId/categories/$categoryId?token=$token";
        $res = $this->getQuest($url);

        return $res;
    }

    public function getData() {
        return $this->data;
    }

    public function setData($data)
    {
        $this->data = $this->decoder($data);
        return $this->data;
    }


    // подготовка запроса для получения урл
    public function getIcreditDataOrder()
    {
        $data = $this->data;

        $email = $data['cart']['order']['email'];
        if ($email == 'virikidorhom@gmail.com') {
            $data['lang']='test';
        }

        /////////////////////////////////////////////////////
        // construct order to icredit
        $total = 0;
        // order to ewcid
        $items = $data['cart']['order']['items'];
        foreach ($items as $key => $item) {
            $ecwidItems[$key]['CatalogNumber'] = $item['sku'];
            $ecwidItems[$key]['Quantity'] = $item['quantity'];
            $ecwidItems[$key]['UnitPrice'] = $item['price'];
            $ecwidItems[$key]['Description'] = $item['name'];

            $total = $total + ($item['quantity'] * $item['price']);

        }

        if (!empty($data['cart']['order']['shippingOption']['shippingRate'])) {

            $ecwidItems[++$key]['CatalogNumber'] = 'delivery';
            $ecwidItems[$key]['Quantity'] = 1;
            $ecwidItems[$key]['UnitPrice'] = $data['cart']['order']['shippingOption']['shippingRate'];
            $ecwidItems[$key]['Description'] = 'delivery';

            // добавляем в расчет чаевые
            $total = $total + $data['cart']['order']['shippingOption']['shippingRate'];
        }

        if ($data['cart']['order']['orderExtraFields']) {

            foreach ($data['cart']['order']['orderExtraFields'] as $item) {

                if ($item['id'] == 'tips') {

                    $tips = (int) $item['value'] / 100;

                    if ($tips > 0) {
                        $ecwidItems[++$key]['CatalogNumber'] = 'tips ' . (int) $item['value'];
                        $ecwidItems[$key]['Quantity'] = 1;
                        $ecwidItems[$key]['UnitPrice'] = $total * $tips;
                        $ecwidItems[$key]['Description'] = 'tips ' . $item['value'];


                    }
                }
            }

        }

        $order['lang']    = $data['lang'];
        $order['items']   = $ecwidItems;
        $order['orderId'] = $data['cart']['order']['id'];
        $order['custom2'] = 'Ecwid';
        $order['email']   = $data['cart']['order']['email'];

        if (!empty($data["cart"]["order"]["billingPerson"]["phone"])) {
            $order["phone"]   = $data["cart"]["order"]["billingPerson"]["phone"];
        }
        $order["name"]    = $data["cart"]["order"]["billingPerson"]["name"];

        if (!empty($data['cart']['order']['discountCoupon'])) {

            if ($data['cart']['order']['discountCoupon']['status'] == 'ACTIVE') {
                $discount = $data['cart']['order']['discountCoupon']['discount'];

                foreach ($order['items'] as $k => $item) {
                    $prise = $item['UnitPrice'];

                    $order['items'][$k]['UnitPrice'] = $prise - ($prise * $discount / 100);
                }

            }

        }


        if ($email == 'virikidorhom@gmail.com') {
//            echo '<pre>';
//            dd($order['items']);
        }

        return $order;
    }

    public function getOrderBuId($orderId)
    {
        $shopId = $this->shop_id;
        $token = $this->secret_token;
        $url = "https://app.ecwid.com/api/v3/$shopId/orders/$orderId?token=$token";
        $res = $this->getQuest($url);

        return $res;
    }

    public function payStatusUpdate($transaction, $status)
    {
        if ($status == 3) {
            $paymentStatus = 'AWAITING_PAYMENT';
        } elseif ($status == 4) {
            $paymentStatus = 'PAID';
        } else {
            $paymentStatus = 'INCOMPLETE';
        }

        $storeId = $this->shop_id;
        $token = $this->secret_token;

        $url = "https://app.ecwid.com/api/v3/$storeId/orders/$transaction" . "?token=$token";
        $data = array(
            'paymentStatus'=> $paymentStatus,
            'externalTransactionId' => $transaction
        );

        $res = $this->putQuest($url, $data);

        return $res;
    }

    public function getAllProducts()
    {
        $shopId = $this->shop_id;
        $token = $this->secret_token;
        $url = "https://app.ecwid.com/api/v3/$shopId/products?token=$token";
        $res = $this->getQuest($url);

        return $res;
    }

    public function getProductsByCategoryId($id)
    {
        $shopId = $this->shop_id;
        $token = $this->secret_token;
        $url = "https://app.ecwid.com/api/v3/$shopId/products?token=$token&category=$id";
        $res = $this->getQuest($url);

        return $res;
    }


    public function getProduct($productId)
    {
        $shopId = $this->shop_id;
        $token = $this->secret_token;
        $url = "https://app.ecwid.com/api/v3/$shopId/products/$productId?token=$token";
        $res = $this->getQuest($url);

        return $res;
    }

    public function deleteAllProductGalleryImages($productId)
    {
        $shopId = $this->shop_id;
        $token = $this->secret_token;
        $url = "https://app.ecwid.com/api/v3/$shopId/products/$productId/gallery?token=$token";

        $res = $this->deleteQuest($url);

       return $res;
    }


    public function setProductImage($productId, $filePatch)
    {
        $shopId = $this->shop_id;
        $token = $this->secret_token;
        $url = "https://app.ecwid.com/api/v3/$shopId/products/$productId/image?token=$token";
        $res = $this->upLoadFile($url, $filePatch);

        return $res;
    }

    public function addProductGalleryImage($productId, $filePatch)
    {
        $shopId = $this->shop_id;
        $token = $this->secret_token;
        $url = "https://app.ecwid.com/api/v3/$shopId/products/$productId/gallery?token=$token";
        $res = $this->upLoadFile($url, $filePatch);

        return $res;
    }


    public static function getPaymentMethod($order)
    {
        if ($order['paymentMethod'] == 'Credit card') {
            $payMethod = 1;
        } elseif ($order['paymentMethod'] == 'PayPal') {
            $payMethod = 3;
        } elseif ($order['paymentMethod'] == 'Сash payment') {
            $payMethod = 2;
        } else {
            $payMethod = 0;
        }

        return $payMethod;
    }

    public static function getAmoDataLead($orderEcwid)
    {
        // формируем массив данных для амо
        $pipelineId = '4651807'; // воронка
        $statusId = '43924885'; // статус

        if ($orderEcwid['paymentMethod'] == 'Сash payment') {
            $payment = 'Оплата наличными по факту';
        } elseif ($orderEcwid['paymentStatus'] == 'PAID') {
            $payment = 'Оплачен';
        }

        // deliwery adress
        $address = '';
        if (!empty($orderEcwid['shippingPerson']['city'])) {
            $address = $orderEcwid['shippingPerson']['city']
                . ' ' . $orderEcwid['shippingPerson']['street'];
        } else {

            if (!empty($orderEcwid['shippingOption'])) {
                if (!empty($orderEcwid['shippingOption']['shippingMethodName'])) {
                    $address = $orderEcwid['shippingOption']['shippingMethodName'];
                }
            }

        }

        if(isset($orderEcwid['orderComments'])) {
            $orderComments = $orderEcwid['orderComments'];
        } else {
            $orderComments = '';
        }

        // date time order
        if (!empty($orderEcwid['shippingOption'])) {

            if ($orderEcwid['shippingOption']['fulfillmentType'] == 'PICKUP') {
                $dateTimeStart = $orderEcwid['extraFields']['ecwid_order_pickup_time'];
            } elseif (!empty($orderEcwid['extraFields']['ecwid_order_delivery_time_interval_start'])) {
                $dateTimeStart = $orderEcwid['extraFields']['ecwid_order_delivery_time_interval_start'];
            } else {
                $dateTimeStart = $orderEcwid['createDate'];
            }

        } else {
            $dateTimeStart = $orderEcwid['createDate'];
        }


        $date = Carbon::parse($dateTimeStart);
        $date->addHour(2);
        $timeHour = (int) $date->format('H');

        if ($timeHour > 14) {
            $timeDelivery = 'Вечер 17-20';
        } else {
            $timeDelivery = 'Утро 11-14';
        }

        $dateOrder = strtotime($date->format('Y-m-d H:i:s'));


        ////////////////////////////////////////////////////////////
        // для тегов

        $tags['pay_method'] = $orderEcwid['paymentMethod'];

        // заказ в подарок
        $present = '';
        foreach ($orderEcwid['orderExtraFields'] as $item) {
            $id = $item['id'];
            if ($id == 'to_presents') {
                $tags[$id] = $item['value'];
                $present = ' ' . $item['value'];
            }
        }

        foreach ($orderEcwid['items'] as $item) {

            $prodId = $item['productId'];
            $tags[$prodId] = $item['quantity'] . 'x - ' . $item['name'];

            if (isset($item['selectedOptions'])) {
                $tags[$prodId] .= $item['selectedOptions'][0]['name']
                . ' ' . $item['selectedOptions'][0]['value'];
            }

        }
        ///////////////////////////////////////////////



        if (!empty($orderEcwid['globalReferer'])) {
            $globalReferer = $orderEcwid['globalReferer'];

        } elseif ($orderEcwid['refererUrl']) {
            $globalReferer = $orderEcwid['refererUrl'];
        }

        if (strlen($globalReferer) > 200) {
            preg_match('/(.+\/\/[A-z.]+)\//', $globalReferer, $maches);
            $globalReferer = $maches[1];
        }


        if (!empty($orderEcwid['extraFields']['gustom_lang'])) {
            // язык витрины
            $ecwidLang = $orderEcwid['extraFields']['gustom_lang'];
        } else {
            $ecwidLang = 'he';
        }


        if ($ecwidLang == 'ru') {
            $ecwidLang = 'Русский';
        } elseif ($ecwidLang == 'en') {
            $ecwidLang = 'Английский';
        } else {
            $ecwidLang = 'Иврит';
        }

        if (empty($orderEcwid['shippingPerson']['name'])) {
            $name = $orderEcwid['billingPerson']['name'];
        } else {
            $name = $orderEcwid['shippingPerson']['name'];
        }



        $dataOrderAmo = [
            'order name'  => 'Ecwid' . $present . ' #' . $orderEcwid['id'],
            'ekwidId'     => $orderEcwid['id'],
            'order price' => $orderEcwid['total'],
            'pipelineId'  => $pipelineId,
            'statusId'    => $statusId,
            'notes'       => $orderComments,
            'lang'        => $ecwidLang,
            'refer_URL'   => $globalReferer,
            'name'        => $name,
            'email'       => $orderEcwid['email'],
            'address'     => $address,
            'payment'     => $payment,
            'date'        => $dateOrder,
            'time'        => $timeDelivery,
            'tags'        => $tags
        ];

        if (empty($orderEcwid['shippingPerson']['phone'])) {
            if (!empty($orderEcwid['billingPerson']['phone'])) {
                $phone = $orderEcwid['billingPerson']['phone'];
            }
        } else {
            $phone = $orderEcwid['shippingPerson']['phone'];
        }

        if (!empty($phone)) {
            $dataOrderAmo['phone'] = $phone;
        }

        if (!empty($orderEcwid['utmData'])) {
            $dataOrderAmo['utmData'] = $orderEcwid['utmData'];
        }


        //////////////////////////////////////
        // заказ в подарок
        foreach ($orderEcwid['orderExtraFields'] as $item) {
            $id = $item['id'];
            if ($id == 'to_presents' || $id == 'presents_name' || $id == 'presents_phone') {
                $presentsArray[$id] = $item['value'];
            }
        }

        if (!empty($presentsArray['to_presents'])) {
            $dataOrderAmo['to_presents'] = $presentsArray;
        }

        return $dataOrderAmo;
    }

    public static function getAmoNotes($orderEcwid)
    {
        $ordersNotes = 'Детали заказа:';

        foreach ($orderEcwid['items'] as $item) {
            $ordersNotes .= "\n" . $item['quantity'] . 'x - ' . $item['name'] . ' ';

            if (isset($item['selectedOptions'])) {
                $ordersNotes .= $item['selectedOptions'][0]['name']
                    . ' ' . $item['selectedOptions'][0]['value'];
            }

        }
        $ordersNotes .= "\n ---------------------- \n";

        // date time order
        if (!empty($orderEcwid['shippingOption'])) {
            if ($orderEcwid['shippingOption']['fulfillmentType'] == 'PICKUP') {
                $dateTimeStart = $orderEcwid['extraFields']['ecwid_order_pickup_time'];
            } elseif (!empty($orderEcwid['extraFields']['ecwid_order_delivery_time_interval_start'])) {
                $dateTimeStart = $orderEcwid['extraFields']['ecwid_order_delivery_time_interval_start'];
            } else {
                $dateTimeStart = $orderEcwid['createDate'];
            }
        } else {
            $dateTimeStart = $orderEcwid['createDate'];
        }
        $date = Carbon::parse($dateTimeStart);
        $date->addHour(2);


        $address = '';
        if (!empty($orderEcwid['shippingPerson']['city'])) {
            $address = $orderEcwid['shippingPerson']['city']
                . ' ' . $orderEcwid['shippingPerson']['street'];
        }


        $timeDelivery = $date->format('Y-m-d H:i');

        $shipping = '';
        if (!empty($orderEcwid['shippingOption'])) {
            if ($orderEcwid['shippingOption']['fulfillmentType'] == 'PICKUP') {
                $shipping = 'Доставка: ' . "\n"
                    . 'Самовывоз ' . $timeDelivery . "\n ---------------------- \n";

            } else {
                $shipping = 'Доставка: ' . "\n Служба доставки - "
                    . $orderEcwid['shippingOption']['shippingMethodName']
                    . "\n Адрес - " . $address
                    . "\n время - " . $timeDelivery
                    . "\n ---------------------- \n";

            }
        }

        if (!empty($orderEcwid['couponDiscount'])) {
            $discount = $orderEcwid['couponDiscount'];
            $total = $orderEcwid['subtotal'];
            $rateDiscount = 100 / ($total / $discount);
            $discount = "скидка - $discount ($rateDiscount%) \n";
        } else {
            $discount = '';
        }

        if(isset($orderEcwid['orderComments'])) {
            $orderComments = $orderEcwid['orderComments'];
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

        $notes = $orderComments . $discount . $shipping . $ordersNotes;

        return $notes;
    }

    public static function amoProductsList($orderItems)
    {
        // добавление товаров в сделку
        foreach ($orderItems as $item) {

            $name = $item['name'];
            if (!empty($item['selectedOptions'][0]['name'])) {

                $name .= ' ' . $item['selectedOptions'][0]['name']
                    . ' ' . $item['selectedOptions'][0]['value'];

            }

            $data[] = [
                'name'        => $name,
                'sku'         => $item['sku'],
                'price'       => $item['price'],
                'quantity'    => $item['quantity'],
                'description' => $item['nameTranslated']['ru']
                .  "\n" . ' - ' . $item['shortDescriptionTranslated']['ru']
            ];
        }

        return $data;
    }

    // уменьшение товаров при новом заказе
    public function productsUpdateCount($data)
    {
        $testDate = false;
        $always_write_off = $this->always_write_off;

        $date = $data['option']['delivery_date'];
        $delivery_date = new Carbon();
        $delivery_date->parse($date);
        $date = new Carbon();
        $test_date = $date->addDays(5);

        if ($delivery_date <= $test_date) {
            $testDate = true;
        }

        foreach ($data['Cart']['items'] as $item) {
            $id = $item['id'];
            $variable_id = (int) $item['variable_id'];
            $count = 0 - (int) $item['count'];
            $product = $this->getProduct($id);
            $product_cetegury = $product['defaultCategoryId'];

            if ($testDate || isset($always_write_off[$product_cetegury])) {

                $log = [
                    'product name' => $product['name'],
                    'product id'   => $product['id'],
                    'product update count' => $count
                ];
                WebhookLog::addLog('new create order (update count) 3 '.$data['Cart']['order_id'], $log);

                if ($variable_id > 0) {
                    $res = $this->productVariationInventory($id, $variable_id, $count);
                    if (isset($res['errorMessage'])) {
                        AppErrors::addError("error product update count", $item);
                    }
                } else {
                    if (empty($product['combinations'])) {
                        $res = $this->productInventory($id, $count);
                        if (isset($res['errorMessage'])) {
                            AppErrors::addError("error product update count", $item);
                        }
                    } else {
                        $size = sizeof($product['combinations']);
                        if ($size == 2) {
                            if (isset($product['combinations'][0]['quantity'])) {
                                $variable_id = $product['combinations'][0]['id'];
                            } else {
                                $variable_id = $product['combinations'][1]['id'];
                            }
                            $res = $this->productVariationInventory($id, $variable_id, $count);
                            if (isset($res['errorMessage'])) {
                                AppErrors::addError("error product update count", $item);
                            }
                        } else {
                            AppErrors::addError("error product update count (combinations size > 2)", $item);
                        }

                    }
                }
            }

        }
    }

    // функция обработки деталей заказа для взаимодействия с платформой еквида
    public function productsService(array $products, array $actions) {

        foreach ($products as $item) {
            $categoryId = $item['categoryId'];

            // для обработки сетов
            if ($categoryId == $this->category_set_id) {
                $productCount = $item['quantity'];
                $subProductsArray = $this->getSubProducts($item);
                if ($subProductsArray) {
                    if ($actions['subProductCountAction'] == 'down') {
                        $this->subProductServiceDown($subProductsArray, $productCount);
                    }
                    if ($actions['subProductCountAction'] == 'up') {
                        $this->subProductServiceUp($subProductsArray, $productCount);
                    }
                }

            }
        }
    }

    private function getSubProducts(array $product)
    {
        $productId = $product['productId'];
        $productData = $this->getProduct($productId);

        $attributes = $productData['attributes'];

        if ($attributes && $attributes[0]['id'] == '94075599') {

            $subProductString = $attributes[0]['value'];
            $subProductString = str_replace(' ', '', $subProductString);
            $subProductData = explode(':', $subProductString);

            $subProductArray = [];
            foreach ($subProductData as $item) {
                $data = explode('-', $item);
                $subProductId = $data[1];

                $subProductArray[$subProductId]['count'] = $data[0];
            }

            return $subProductArray;
        } else {
            return false;
        }
    }


    // уменьшаем количество продукта
    private function subProductServiceDown(array $subproductArray, $productCount)
    {
        foreach ($subproductArray as $subId => $item) {
            $subCount = $item['count'];

            $allSubCount = $productCount * $subCount;
            $count = 0 - $allSubCount;

            $res = $this->productInventory($subId, $count);
            $result[$subId] = $res;
        }

        return $result;
    }

    // уменьшаем количество продукта
    private function subProductServiceUp(array $subproductArray, $productCount)
    {
        foreach ($subproductArray as $subId => $item) {
            $subCount = $item['count'];

            $allSubCount = $productCount * $subCount;
            $count = 0 + $allSubCount;

            $res = $this->productInventory($subId, $count);
            $result[$subId] = $res;
        }

        return $result;
    }


    private function productInventory($productId, $count)
    {

        $shopId = $this->shop_id;
        $token = $this->secret_token;
        $url ="https://app.ecwid.com/api/v3/$shopId/products/$productId/inventory?token=$token";

        $data = array(
            'quantityDelta'=> $count
        );

        $res = $this->putQuest($url, $data);

        return $res;
    }

    private function productVariationInventory($productId, $variable_id, $count)
    {

        $shopId = $this->shop_id;
        $token = $this->secret_token;
        $url ="https://app.ecwid.com/api/v3/$shopId/products/$productId/combinations/$variable_id/inventory?token=$token";

        $data = array(
            'quantityDelta'=> $count
        );

        $res = $this->putQuest($url, $data);

        return $res;
    }


    // эта функция подготавливает массив для создания документа в GreenInvoice
    public static function getDataToGreenInvoice(array $data)
    {
        $lang = 'he';
        $dateStr = $data['updateDate'];
        $dates = explode(' ', $dateStr);
        $date = $dates[0];


        $orderData['email'] = $data['email'];

        if (!empty($data['billingPerson']['name'])) {
            $name = trim($data['billingPerson']['name']);
        } elseif (!empty($data['shippingPerson']['name'])) {
            $name = trim($data['shippingPerson']['name']);
        }

        $name = AppServise::TransLit($name);
        $orderData['name'] = $name;

        if (!empty($data['extraFields']['gustom_lang'])) {
            $orderData['lang'] = $data['extraFields']['gustom_lang'];
        } else {
            $orderData['lang'] = 'he';
        }



        if (!empty($data['billingPerson']['phone'])) {
            $orderData['phone'] = $data['billingPerson']['phone'];
        } elseif (!empty($data['shippingPerson']['phone'])) {
            $orderData['phone'] = $data['shippingPerson']['phone'];
        }

        if (!empty($data['billingPerson']['city'])) {
            $orderData['city'] = AppServise::TransLit($data['billingPerson']['city']);
            $orderData['address'] = AppServise::TransLit($data['billingPerson']['street']);
        } elseif (!empty($data['shippingPerson']['city'])) {
            $orderData['city'] = AppServise::TransLit($data['shippingPerson']['city']);
            $orderData['address'] = AppServise::TransLit($data['shippingPerson']['street']);
        }


        $orderData['remarks'] = $data['id'] . " פרטים - מספר הזמנה: " ;
        $orderData['orderNames'] =  $data['id'] . " מספר הזמנה: ";

        foreach ($data['items'] as $item) {
            $items[] =  [
                "catalogNum"   => $item['sku'],
                "description"  => $item['name'],
                "quantity"     => $item['quantity'],
                "price"        => $item['price'],
                "currency"     => "ILS",
                "currencyRate" => 1,
                "vatType"      => 0
            ];

            $size = '';
            if (!empty($item['selectedOptions'])) {
//                if ($item['selectedOptions'][0]['name'] == 'size')
                $size = $item['selectedOptions'][0]['nameTranslated'][$lang] . '-' . $item['selectedOptions'][0]['valueTranslated'][$lang];
            }

            $total = $item['quantity'] * $item['price'];

            $orderData['remarks'] .= "\n ILS $total = {$item['quantity']} x ILS {$item['price']} : $size {$item['nameTranslated'][$lang]}";

            $orderData['orderNames'] .= "\n {$item['nameTranslated'][$lang]} $size ({$item['quantity']}) ";
        }
        $orderData['items'] = $items;

        if (!empty($data['shippingOption']['shippingRate'])) {
            $delivery = "\n delivery: ";

            foreach ($data['shippingPerson'] as $key => $val) {
                $val = AppServise::TransLit($val);
                $delivery = $delivery . "\n $key : $val ";
            }
            $orderData['delivery'] = $delivery;

            // стоимоссть доставки
            $orderData['delivery'] = "\n ILS {$data['shippingOption']['shippingRate']} ..............." . $orderData['delivery'];

        }

        foreach ($data['customSurcharges'] as $item) {

            if ($item['id'] == 'tips') {

                $tips = (int) $item['value'] / 100;

                $orderData['tips'] = "\n ___________________\n טיפים: " . $item['value'] . "%\n" . 'ILS '  . $item['total']." ...........";
            }
        }


        if (isset($data['externalTransactionId'])) {
            $orderData['payId'] = $data['externalTransactionId'];
        } else {
            $orderData['payId'] = '';
        }
        $orderData['total'] = $data['total'];

        // test mode
        if($orderData['email'] == 'virikidorhom@gmail.com') {
            $orderData['total'] = 1;
        }

        $orderData['payDate'] = $date;

        if (!empty($data['paymentModule'])) {
            if ($data['paymentModule'] == self::$payModyle || $data['paymentModule'] == 'PayPalStandard') {
                $orderData['type'] = 3;
            }
            if ($data['paymentModule'] == self::$payModyle) {

                $orderData['bankName'] = 'iCredit';

            } elseif ($data['paymentModule'] == 'PayPalStandard') {

                $orderData['bankName'] = 'PayPal';

            }
        } else {
            $orderData['type'] = 1;
            $orderData['bankName'] = 'none';
        }

        return $orderData;
    }

    public function getDiscountCoupons()
    {
        $shopId = $this->shop_id;
        $token = $this->secret_token;
        $url = "https://app.ecwid.com/api/v3/$shopId/discount_coupons?token=$token";
        $res = $this->getQuest($url);

        return $res;
    }

    // обновление статусов на еквиде
    public function orderInAmoStatusUpdate($orderId, $amoStatus, $statusPay)
    {
        switch ($amoStatus) {
            case 34990069:
                $orderStatus = 'AWAITING_PROCESSING';
                break;
            case 43471420:
                $orderStatus = 'PROCESSING';
                break;
            case 35584276:
                $orderStatus = 'OUT_FOR_DELIVERY';
                break;
            case 142:
                $orderStatus = 'DELIVERED';
                break;
        }

        switch ($statusPay) {
            case 436781:
                $paymentStatus = 'PAID';
                break;
            case 436783:
                $paymentStatus = 'AWAITING_PAYMENT';
                break;
            default:
                $paymentStatus = 'INCOMPLETE';
                break;
        }

        $storeId = $this->shop_id;
        $token = $this->secret_token;
        $url = "https://app.ecwid.com/api/v3/$storeId/orders/$orderId" . "?token=$token";

        $data = array(
            'paymentStatus'=> $paymentStatus,
            'fulfillmentStatus'=> $orderStatus
        );

        $res = $this->putQuest($url, $data);
        $res['data'] = $data;

        return $res;
    }

    public function paymentStatusUpdate($orderId, $statusPay)
    {
        $storeId = $this->shop_id;
        $token = $this->secret_token;
        $url = "https://app.ecwid.com/api/v3/$storeId/orders/$orderId" . "?token=$token";

        $data = array(
            'paymentStatus'=> $statusPay
        );

        $res = $this->putQuest($url, $data);
        $res['data'] = $data;

        return $res;
    }

    public function createNewOrder($data)
    {
        $storeId = $this->shop_id;
        $token = $this->secret_token;
        $url = "https://app.ecwid.com/api/v3/$storeId/orders?token=$token";

        $data_json = json_encode($data);
        $res = $this->postQuest($url, $data_json);
        return $res;
    }

    public function deleteOrder($id)
    {

        $storeId = $this->shop_id;
        $token = $this->secret_token;
        $url = "https://app.ecwid.com/api/v3/$storeId/orders/$id?token=$token";

        $res = $this->deleteQuest($url);

        return $res;
    }


    // загрузка файлов
    private function uploadFile($url, $filePatch)
    {
        $file = Storage::disk('local')->get($filePatch);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL,$url);
        curl_setopt($ch, CURLOPT_POST,1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $file);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: image/jpeg;'));

        $result = curl_exec($ch);
        curl_close ($ch);

        return json_decode($result, true);
    }

    private function getQuest($url)
    {
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "GET",
            CURLOPT_HTTPHEADER => [
                "Accept: application/json"
            ],
        ]);

        $response = curl_exec($curl);
        $err = curl_error($curl);
        curl_close($curl);

        if ($err) {
            var_dump($err);
        }

        return json_decode($response, true);
    }

    private function postQuest($url, $data)
    {


        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_POSTFIELDS => "$data",
            CURLOPT_HTTPHEADER => [
                "Accept: application/json",
                "Content-type: application/json"
            ],

        ]);


        $response = curl_exec($curl);
        $err = curl_error($curl);
        $errno = curl_errno($curl);
        $http_code  = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        $error = [
            'err' => $err,
            'errno' => $errno,
            'http_code' => $http_code
        ];
        if ($http_code > 200) {
            print_r($response);
            dd($error);
        }

        return json_decode($response, true);
    }

    public function putQuest($url, $data)
    {
        $data_json = json_encode($data);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json','Content-Length: ' . strlen($data_json)));
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data_json);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            var_dump($err);
        }

        return json_decode($response, true);
    }

    public function deleteQuest($url)
    {
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "DELETE",
            CURLOPT_HTTPHEADER => [
                "Accept: application/json"
            ],
        ]);

        $response = curl_exec($curl);
        $err = curl_error($curl);

        curl_close($curl);

        if ($err) {
            AppErrors::addError('Ecvid Service - delete product gallery images', $err);
        }

        return json_decode($response, true);
    }

}
