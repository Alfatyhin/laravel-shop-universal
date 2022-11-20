<?php


namespace App\Http\Controllers;


use App\Models\Categories;
use App\Models\Clients;
use App\Models\Coupons;
use App\Models\Orders as OrdersModel;
use App\Models\Product;
use App\Models\ProductOptions;
use App\Models\UtmModel;
use App\Services\AmoCrmServise;
use App\Services\AppServise;
use App\Services\EcwidService;
use App\Services\OrderService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Facades\Image;
use Intervention\Image\ImageManagerStatic as ImageManager;

class ShopSettingController extends Controller
{
    private $image_sizes =  ['1500', '800', '400', '160'];

    public function index(Request $request)
    {
        return parent::index($request); // TODO: Change the autogenerated stub
    }

    public function Orders(Request $request)
    {

        $paymentMethod = AppServise::getOrderPaymentMethod();
        $paymentStatus = AppServise::getOrderPaymentStatus();
        $invoiceStatus = AppServise::getOrderInvoiceStatus();

        $order_id = false;
        $orderSearch = false;


        if (!empty($request->get('order_id'))) {

            $order_id = $request->get('order_id');

            $orderSearch =  DB::table('orders')
                ->where('orders.order_id', $order_id)
                ->join('clients', 'orders.clientId', '=', 'clients.id')
                ->select('orders.*', 'clients.name', 'clients.email')
                ->first();

            if (!$orderSearch) {
                $orderSearch = OrdersModel::where('order_id', $order_id)->first();
            }
            if (!$orderSearch) {
                dd($orderSearch);
            }
        }

        if (!empty($request->get('date-from')) && !empty($request->get('date-to'))) {

            $date_from = new Carbon($request->get('date-from'));
            $date_to = new Carbon($request->get('date-to') . ' 23:59');

        } elseif ($request->get('dates')) {

            if ($request->get('dates') == 'today') {
                $date = new Carbon();
                $date_from = new Carbon($date->format('Y-m-d'));
                $date_to = new Carbon($date->format('Y-m-d 23:59'));

            }
            if ($request->get('dates') == 'month') {

                $date = new Carbon('first day of this month');
                $date_from = new Carbon($date->format('Y-m-d 00:00'));

                $date = new Carbon('last day of this month');
                $date_to = new Carbon($date->format('Y-m-d 23:59'));
            }

        } else {

            if (session()->has('dates')) {
                $dates = session('dates');
                $date_from = $dates['date_from'];
                $date_to = $dates['date_to'];

            } else {

                $date_from = new Carbon('first day of this month' . ' 00:00');
                $date_to = new Carbon('last day of this month' . ' 23:59');
            }

        }


        $dates['date_from'] = $date_from;
        $dates['date_to'] = $date_to;
        session(['dates' => $dates]);
        session()->save();

        // для таблицы
        $orders = DB::table('orders')
            ->where('orders.deleted_at', null)
            ->whereBetween('orders.created_at', [$date_from, $date_to])
            ->whereBetween('orders.paymentStatus', [2, 4])
            ->latest('orders.id')
            ->join('clients', 'orders.clientId', '=', 'clients.id')
            ->select('orders.*', 'clients.name', 'clients.email')
            ->paginate(10);

        $utm_orders = UtmModel::whereBetween('created_at', [$date_from, $date_to])->get()->keyBy('order_id')->toArray();


        // статистика
        $paydPeriodInfo['заказов'] = DB::table('orders')
            ->where('orders.deleted_at', null)
            ->whereBetween('orders.created_at', [$date_from, $date_to])
            ->whereBetween('orders.paymentStatus', [2, 4])->count('id');


        foreach ($paymentMethod as $kpm => $method_name) {
            foreach ($paymentStatus as $kps => $status) {
                $summ = DB::table('orders')
                    ->where('orders.deleted_at', null)
                    ->whereBetween('orders.created_at', [$date_from, $date_to])
                    ->where('orders.paymentMethod', $kpm)
                    ->where('orders.paymentStatus', $kps)->sum('orderPrice');

                if ($summ > 0) {
                    $paydPeriodInfo['orders'][$kpm][$kps]['summ'] = $summ;
                    $paydPeriodInfo['orders'][$kpm][$kps]['count'] = DB::table('orders')
                        ->where('orders.deleted_at', null)
                        ->whereBetween('orders.created_at', [$date_from, $date_to])
                        ->where('orders.paymentMethod', $kpm)
                        ->where('orders.paymentStatus', $kps)->count('id');
                }
            }
        }



        $date_start = new Carbon('first day of this month');
        $date_end = new Carbon('last day of this month');

        $priceMonth = OrdersModel::whereBetween('created_at', [$date_start, $date_end])
            ->sum('orderPrice');

        $priceYear = OrdersModel::whereYear('created_at', $date_start->format('Y'))
            ->sum('orderPrice');

        return view('shop-settings.orders', [
            'orders'         => $orders,
            'paymentMethod'  => $paymentMethod,
            'paymentStatus'  => $paymentStatus,
            'invoiceStatus'  => $invoiceStatus,
            'priceMonth'     => $priceMonth,
            'paydPeriodInfo' => $paydPeriodInfo,
            'date_from'      => $date_from,
            'date_to'        => $date_to,
            'priceYear'      => $priceYear,
            'order_id'       => $order_id,
            'orderSearch'    => $orderSearch,
            'message'        => $request->message,
            'utm_orders'     => $utm_orders
        ]);
    }


    public function Categories(Request $request)
    {


        $categories = Categories::all()->sortBy('index_num')->keyBy('id');
        $products = Product::all()->sortBy('index_num')->keyBy('id');


        return view('shop-settings.categories', [
            'message' => $request->message,
            'categories' => $categories,
            'products' => $products
        ]);

    }


    public function Products(Request $request)
    {

        $categories = Categories::all()->sortBy('index_num')->keyBy('id');
        $products = Product::all()->sortBy('index_num')->keyBy('id');

        $product_options = ProductOptions::all()->keyBy('id')->toArray();

        foreach ($product_options as &$item) {
            $item['options'] = json_decode($item['options'], true);
        }


        $empty_categories = [];



        return view('shop-settings.products', [
            'message' => $request->message,
            'categories' => $categories,
            'products' => $products,
            'empty_categories' => $empty_categories
        ]);

    }

    public function DeyOffer(Request $request)
    {

        $post = $request->post();
        if (!empty($post)) {
            $offer_id = $post['dey_offer_id'];
            $title = $post['title'];
            $dey_offer_data = [
                'id' => $offer_id,
                'title' => $title
            ];

            Storage::disk('local')->put('data/dey-offer.json', json_encode($dey_offer_data));
            $message[] = 'dey offer save';
        }

        $categories = Categories::where('enabled', 1)->get()->sortBy('index_num')->keyBy('id');
        $products = Product::where('enabled', 1)->get()->sortBy('index_num')->keyBy('id');
        $products = AppServise::ProductsShopPrepeare($products, $categories);



        $dey_offer_data = false;
        if (Storage::disk('local')->exists('data/dey-offer.json')) {
            $dey_offer_json = Storage::disk('local')->get('data/dey-offer.json');
            $dey_offer_data = json_decode($dey_offer_json, true);
        }



        return view('shop-settings.dey_offer', [
            'message' => $request->message,
            'categories' => $categories,
            'products' => $products,
            'dey_offer_data' => $dey_offer_data
        ]);
    }


    public function migration(Request $request)
    {

        $command = $request->get('command');
        if ($command) {
            Artisan::call('migrate:' . $command);
        } else {
            Artisan::call('migrate');
        }


        echo 'done';
    }

    public function sortableSave(Request $request)
    {
        $save = $request->get('event_sortable');
        $name = $request->get('name');
        $sort = $request->get('sort');
        if (!empty($save) && !empty($name)) {
            if ($name == 'categories') {
                $models = Categories::all();

                foreach ($sort as $id => $num) {
                    $model = $models->find($id);
                    $model->index_num = $num;
                    $model->save();
                }

                session()->flash('message', ["sortable $name save"]);

                return redirect(route('shop_settings_categories'));

            } elseif ($name == 'product_galery') {
                $product = Product::where('id', $request->get('product_id'))->first();
                $image_galery = json_decode($product->galery, true);

//                foreach ($image_galery as &$data) {
//                    foreach ($data as &$item) {
//                        $item = str_replace('jpg', 'webp', $item);
//                    }
//                }

                foreach ($sort as $image_key => $v) {
                    $new_galery[] = $image_galery[$image_key];
                }

                $product->galery = json_encode($new_galery);
                $product->image = json_encode($new_galery[0]);
                $product->save();

                session()->flash('message', ["image sort"]);

                return back();

            } else {
                dd($name, $sort);

            }

        }
    }


    public function imageDownload(Request $request)
    {

        if ($request->hasFile('image')) {

            $request->validate([
                'image'     => 'required|image|mimes:jpeg,jpg,png,webp'
            ]);

            $file = $request->file('image');

            $file_name = $file->getClientOriginalName();
            $image_name = time();
            $path = 'public/images';
            if (Storage::exists("$path/$image_name.webp")) {
                session()->flash('message', ["image for this name isset, please rename download file"]);

                return back();
            }
            Storage::putFileAs($path, $file, $file_name);
            $file_path = $path.'/'.$file_name;
            $source = str_replace('public', 'storage', $file_path);

            $new_image = "storage/images/$image_name.webp";
            $pathes['originalImageUrl'] = '/'.$new_image;

            ImageManager::make($source)
                ->encode('webp', 100)
                ->save($new_image, 80);

            $images_sizes = $this->image_sizes;

            foreach ($images_sizes as $size) {

                $new_image = "storage/images/$size/$image_name.webp";
                $key = "image".$size."pxUrl";
                $pathes[$key] = '/'.$new_image;

                ImageManager::make($source)
                    ->resize($size, null, function ($constraint) {
                        $constraint->aspectRatio();
                    })
//                    ->crop($size, $size)
                    ->encode('webp', 100)
                    ->save($new_image, 100);
            }
            if (!preg_match("/webp$/", $file_name)) {
                Storage::delete($path."/".$file_name);
            }

            $image_to = $request->get('image_to');
            $id = $request->get('id');

            if ($image_to == 'category') {

                $category = Categories::where('id', $id)->first();
                $category_image_data = json_decode($category->image, true);

                if ($category_image_data) {
                    foreach ($category_image_data as $old_image) {

                        $old_image = str_replace('storage', 'public', $old_image);
                        if (Storage::exists($old_image)) {
                            $res = Storage::delete($old_image);
                        }
                    }
                }

                $category->image = json_encode($pathes);
                $category->save();

                session()->flash('message', ["image download"]);

                return redirect(route('shop_settings_categories'));

            }

            if ($image_to == 'product') {
                $product = Product::where('id', $id)->first();

                $image_galery = json_decode($product->galery, true);
                $image_galery[] = $pathes;

                $product->galery = json_encode($image_galery);
                $product->image = json_encode($image_galery[0]);
                $product->save();

                session()->flash('message', ["image add"]);

                return redirect(route('product_redact', ['product' => $product]));

            }
        }

    }

    public function imageTest(Request $request)
    {
        dd('stop');
        $products = Product::all();

        foreach ($products as $product) {
            $images = json_decode($product->galery, true);
            $flag = false;
            if ($images) {
                foreach ($images as $ki => &$item) {
                    foreach ($item as &$path) {
                        $path_data = explode('/', $path);
                        $path_data = array_slice($path_data,1);
                        $path_old = implode('/', $path_data);
                        $file_names = last($path_data);
                        $file_data = explode('.', $file_names);
                        $filename = $product->id . "_" . $ki . "_product";
                        $filename_del = $product->id . "_product";
                        $new_path_data = array_slice($path_data, 0,-1);
                        $new_path = "/" . implode('/', $new_path_data) . "/$filename.webp";
                        $new_path_del = "/" . implode('/', $new_path_data) . "/$filename_del.webp";

                        if (preg_match('/webp$/', $file_names)) {
                            if (!preg_match('/_product/', $file_names)) {
                                $flag = true;

                                if (Storage::disk('public_root')->exists($path)) {

                                    if (!Storage::disk('public_root')->exists($new_path)) {
                                        Storage::disk('public_root')->copy($path, $new_path);
                                    }
                                    $img_delete[] = $path;
                                    $img_delete[] = $new_path_del;

                                } else {
//                                Storage::disk('public_root')->copy('/storage/images/160/3p-1662726324.webp', '/storage/images/160/2921468169.webp');
//                                dd('test 2', $path, $product->id);
                                }
                                $path = "$new_path";
                            }

                        } else {
//                            dd($path_old, $path);
//                            $path = "$new_path";
//                            $flag = true;
//                            $file_path = "public/" . implode('/', array_slice($path_data, 1));
//                            if (Storage::disk('public_root')->exists($path) && $file_data[1] != 'webp') {
//
//                                $flag = true;
//                                $img = ImageManager::make($path_old)->encode('webp', 100);
//                                $img->save($new_path, 100);
//                                $img->destroy();
//                                Storage::disk('public_root')->delete($path_old);
//                            }
                        }

                    }
                }
            }


            if ($flag) {
                $product->galery = json_encode($images);
                $product->image = json_encode($images[0]);
                $product->save();

            }
        }

        if (isset($img_delete)) {
            foreach ($img_delete as $old_img) {
                if (Storage::disk('public_root')->exists($old_img)) {
                    Storage::disk('public_root')->delete($old_img);
                }
            }
        }
        dd('done');

    }

    public function imageDelete(Request $request)
    {
        $image_to = $request->get('image_to');
        $id = $request->get('id');

        if ($image_to == 'product') {
            $image_key = $request->get('img_key');
            $product = Product::where('id', $id)->first();

            $image_galery = json_decode($product->galery, true);
            $image_data = $image_galery[$image_key];

            foreach ($image_data as $old_image) {

                $old_image = str_replace('storage', 'public', $old_image);
                if (Storage::exists($old_image)) {
                    $res = Storage::delete($old_image);
                }
            }
            unset($image_galery[$image_key]);

            $image_galery = array_slice($image_galery, 0);

            $product->galery = json_encode($image_galery);
            if (isset($image_galery[0])) {
                $product->image = json_encode($image_galery[0]);
            } else {
                $product->image = null;
            }
            $product->save();

            session()->flash('message', ["image delete"]);

            return redirect(route('product_redact', ['product' => $product]));

        }
    }

    public function clientData(Request $request, Clients $client)
    {
        $client->data = json_decode($client->data, true);
        $amoCrmService = new AmoCrmServise();
        $amo_clones = $amoCrmService->getContactDoubles($client->email);

        $amo_contact = $amoCrmService->getContactBuId($client->amoId);

        if (!$amo_contact && $amo_clones) {
            $amo_clones_rev = array_reverse($amo_clones);
            $client->amoId = $amo_clones_rev[0]['id'];
            $client->save();
        }


        return view('shop-settings.client', [
            'message' => $request->message,
            'client' => $client,
            'amo_clones' => $amo_clones
        ]);

    }

    public function updateAmoContact(Request $request, Clients $client)
    {
        $phone = OrderService::phoneAmoFormater($client->phone);
        $contactData = [
            'name' => $client->name,
            'phone' => $phone,
            'email' => $client->email,
        ];
        $client_data = json_decode($client->data, true);

        if (isset($client_data['clientBirtDay'])) {
            $client_data_birthday = AppServise::dateFormater($client_data['clientBirtDay']);
            if ($client_data_birthday) {
               $client_data['clientBirthDay'] = $client_data_birthday;
            } else {
                $client_data['clientBirthDayStr'] = $client_data['clientBirtDay'];
            }
            unset($client_data['clientBirtDay']);
            $client->data = json_encode($client_data);
            $client->save();
        }

        if (isset($client_data['clientBirthDay']) && !empty($client_data['clientBirthDay'])) {
            $date = AppServise::dateFormater($client_data['clientBirthDay']);

            if ($date) {
                $date = new Carbon($date);
                $date_time = strtotime($date->format('Y-m-d H:i:s'));
                $contactData['birthday'] = $date_time;
            }
        }

        $amoCrmService = new AmoCrmServise();

        if (!empty($client->amoId)) {
            $contact = $amoCrmService->getContactBuId($client->amoId);
            $contact = $amoCrmService->syncContactData($contact, $contactData);
        }
        session()->flash('message', ['amo contact update']);

        return back();
    }

    public function delivery(Request $request)
    {

        $categories = Categories::where('enabled', 1)->get()->sortBy('index_num')->keyBy('id');

        $delivery_json = Storage::disk('local')->get('js/delivery.json');
        $cityes_json = Storage::disk('local')->get('js/israel-city.json');

        $delivery = json_decode($delivery_json, true);
        $cityes = json_decode($cityes_json, true);

        $shop_setting = Storage::disk('local')->get('js/shop_setting.json');
        $shop_setting = json_decode($shop_setting, true);

//        dd($delivery, $cityes);


        return view('shop-settings.delivery', [
            'message'    => $request->message,
            'categories' => $categories,
            'cityes'     => $cityes,
            'delivery'   => $delivery,
            'shop_setting' => $shop_setting,
        ]);
    }

    public function deliverySave(Request $request)
    {

        $shop_setting = $request->get('shop');

        if (!empty($shop_setting)) {
            $res = Storage::disk('local')->put('js/shop_setting.json', json_encode($shop_setting));
            if($res) {
                session()->flash('message', ['shop setting date-time delivery save']);
                return redirect(route('delivery'));
            }
        }

        $delivery = $request->post('delivery');
        dd($delivery);

        if (!empty($delivery)) {
            $delivery_cityes = $request->post('city');
            foreach ($delivery as $k => $value) {
                if (!empty($delivery_cityes[$k])) {
                    $delivery[$k]['cityes'] = $delivery_cityes[$k];
                }
                if (empty($delivery[$k]['cityes'])
                    || empty($value['min_sum_order'])
                    || empty($value['rate_delivery'])) {
                    unset($delivery[$k]);
                } else {
                    foreach ($delivery_cityes[$k] as $city) {
                        $data_cityes[$city][] = $k;
                    }
                }
                foreach ($value['rate_delivery_to_summ_order'] as $k_rate => $val) {
                    if (!isset($val['sum_order']) || !isset($val['rate_delivery'])) {
                        unset ($delivery[$k]['rate_delivery_to_summ_order'][$k_rate]);
                    }
                }
            }

            $delivery_data['delivery'] = $delivery;
            $delivery_data['cityes_data'] = $data_cityes;
            $res = Storage::disk('local')->put('js/delivery.json', json_encode($delivery_data));
            if($res) {
                session()->flash('message', ['delivery save']);
                return redirect(route('delivery'));
            }
        }



        $save_cityes = $request->post('save_cityes');
        if (!empty($save_cityes)) {
            $cityes = Storage::disk('local')->get('js/israel-city.json');
            $cityes = json_decode($cityes, true);
            $cityes_new = $request->post('city');
            foreach ($cityes_new as $k => $value) {
                if (empty($value['ru']) || empty($value['he'])) {
                    unset($cityes_new[$k]);
                }
            }
            $cityes['citys_all'] = $cityes_new;
            $res = Storage::disk('local')->put('js/israel-city.json', json_encode($cityes));
            if($res) {
                session()->flash('message', ['city save']);
                return back();
            }
        }

    }

    public function appInvoiceSetting(Request $request)
    {

        $dataJson = Storage::disk('local')->get('data/app-setting.json');
        $settingData = json_decode($dataJson, true);


        $invoice_mode_paypal = $request->get('invoice_mode_paypal');
        if ($invoice_mode_paypal) {
            $settingData['invoice_mode_paypal'] = $invoice_mode_paypal;
        }

        $invoice_mode_cache = $request->get('invoice_mode_cache');
        if ($invoice_mode_cache) {
            $settingData['invoice_mode_cache'] = $invoice_mode_cache;
        }

        $invoice_mode_bit = $request->get('invoice_mode_bit');
        if ($invoice_mode_cache) {
            $settingData['invoice_mode_bit'] = $invoice_mode_bit;
        }

        if ($invoice_mode_paypal) {
            Storage::disk('local')->put('data/app-setting.json', json_encode($settingData));
        }

        return view('shop-settings.invoice_setting', [
            'message'    => $request->message,
            'settingData' => $settingData,
        ]);
    }

    public function banner(Request $request)
    {

        $post = $request->post();

        if (Storage::disk('local')->exists('data/banner.json')) {
            $banner = Storage::disk('local')->get('data/banner.json');
            $banner = json_decode($banner, true);
        } else {
            $banner = ['en' => '', 'ru' => '', 'he' => ''];
        }

        if ($post) {
            $res = Storage::disk('local')->put('data/banner.json', json_encode($post['banner']));
            if($res) {
                session()->flash('message', ['banner save']);
                return redirect(route('banner'));
            }
        }

        return view('shop-settings.banner', [
            'message' => $request->message,
            'banner'  => $banner
        ]);
    }

    public function testOrderChangeCount(Request $request, OrdersModel $order)
    {
        $post = $request->post();
        $OrderService = new OrderService();
        $OrderService->changeProductsCountTest($order);

    }

    public function scriptsModules(Request $request)
    {
        $files = Storage::disk('views_shop')->files('layouts/seo');

        foreach ($files as $file_path) {
            $file_name = last(explode('/', $file_path));
            $files_data[] = [
                'file_path' => $file_path,
                'file_name' => $file_name,
                'file_text' => Storage::disk('views_shop')->get($file_path)
            ];
        }
        dd($files_data);
    }

    public function productOptions (Request $request)
    {
        $products_options = ProductOptions::all()->keyBy('id');
        $shop_langs = AppServise::getLangs();

        $options_select = ['SELECT' => 'список', 'SIZE' => 'размер', 'RADIO' => 'выбор', 'CHECKBOX' => 'флажки', 'TEXT' => 'текстовое поле'];


//        dd($products_options);


        return view('shop-settings.products_options', [
            'message' => $request->message,
            'products_options' => $products_options,
            'options_select' => $options_select,
            'shop_langs' => $shop_langs
        ]);
    }

}
