
<div class="feedback__text">
    <div class="title-custom">
        <h1>
            <span>Свяжитесь с нами</span>
        </h1>
    </div>
    <p>
        Если у вас есть вопросы или вам нужна помощь с выбором, сообщите нам об этом, мы с радостью вам поможем
    </p>
    <div class="items">
        <div class="item">
            <div class="icon">
                <div class="img">
                    <img src="/assets/images/icons/phone-icon.png" alt="">
                </div>
                <span>
                                           PHONE
                                        </span>
            </div>
            <p>
                0559475812
            </p>
        </div>
        <div class="item">
            <div class="icon">
                <div class="img">
                    <img src="/assets/images/icons/mail-icon.png" alt="">
                </div>
                <span>
                                            EMail
                                        </span>
            </div>
            <a href="tel:info@agency.com">
                info@agency.com
            </a>
        </div>
        <div class="item">
            <div class="icon">
                <div class="img">
                    <img src="/assets/images/icons/addres-icon.png" alt="">
                </div>
                <span>
                                            Address
                                        </span>
            </div>
            <p>
                Emanuel Ringelblum 3, Holon
            </p>
        </div>
        <div class="item">
            <div class="icon">
                <div class="img">
                    <img src="/assets/images/icons/compas-icon.png" alt="">
                </div>
                <span>
                                            График
                                            работы
                                        </span>
            </div>
            <p>
                вс - чт с 10:00 до 20:00, <br>
                пт с 10 до 16, вс - выходной
            </p>
        </div>
    </div>
</div>

<div class="feedback__form">
    <form action="{{ route('send_contact_form') }}" method="POST">
        @csrf
        <label>
            <span>Ваше имя*</span>
            <input type="text" name="clientName">
        </label>
        <label class="phone-mask" for="" class="@error('phone') error @enderror">
            <span>Ваш Pone*</span>
            <input hidden class="phone" name="phone" value="">
            <p>
                {{ __('shop-cart.Телефон') }} *
            </p>
        </label>
        <label>
            <span>Ваш вопрос*</span>
            <textarea name="question"></textarea>
        </label>
        <button type="submit" class="main-btn">Задать  вопрос</button>
    </form>
</div>