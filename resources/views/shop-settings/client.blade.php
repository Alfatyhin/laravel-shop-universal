@extends('layouts.master')

@section('title', 'Заказы')


@section('head')



@stop

@section('sidebar')
    @parent
@stop
@section('left_sidebar')
    <li class="client" data_name="client">
        <a href="#" >клиент</a>
    </li>
    @parent
@stop

@section('content')



    <div>
        <h3> Информация о клиенте №{{ $client->id }}</h3>
        <p> <b>имя:</b> {{ $client->name }} </p>
        <p>
            <b>email</b>
            <a href="mailto:{{ $client->email }}">{{ $client->email }}</a>
        </p>
        <p>
            <b>tel:</b>
            <span>{{ $client->phone }}</span>
            @isset($client->data['phones'])
                @foreach($client->data['phones'] as $phone)
                    <br><span>{{ $phone }}</span>
                @endforeach
            @endisset
        </p>
        <p><b>amo ID</b>
            <a href="https://takebreak.amocrm.ru/contacts/detail/{{ $client->amoId }}">
                {{ $client->amoId }}
            </a>

            <a class="button" href="{{ route('update_amo_contact', ['client' => $client]) }}">update to Amo</a>
        </p>


        @if (!empty($amo_clones) && sizeof($amo_clones) > 1)
            <div class="box_border">
                <p>amo clones</p>
                @foreach($amo_clones as $clone)
                    @if ($client->amoId != $clone['id'])
                        <br> <a href="https://takebreak.amocrm.ru/contacts/detail/{{ $clone['id'] }}">
                            <b>имя:</b> {{ $clone['name'] }} <b>amo ID</b> {{ $clone['id'] }}
                        </a>

                    @endif
                @endforeach
            </div>

        @endif

        @if(!empty($client->data))
            <h2>Client data</h2>
            @foreach($client->data as $key => $item)
                <div class="pre">
                    <b>{{ $key }} - </b> <span class="pre">@php(print_r($item))</span>
                </div>
            @endforeach
        @endif

        @if(!empty($client_orders))
            <h2>Client orders</h2>
            <hr>
            @foreach($client_orders as $key => $item)
                <p>
                    order id: <b>{{ $item['order_id'] }}</b> <br>
                    сумма: {{ $item['orderPrice'] }} статус оплаты - {{ $paymentStatus[$item['paymentStatus']] }}<br>
                    чек: {{ $invoiceStatus[$item['invoiceStatus']] }}<br>
                    дата создания: {{ $item['created_at'] }} <br>
                    посленее изменение: {{ $item['updated_at'] }} <br>

                    <a class="button" href="{{ route('shop_settings_orders', ['order_id' => $item['order_id']]) }}">
                        найти
                    </a>
                </p>
                <hr>
            @endforeach
        @endif
    </div>

@stop


