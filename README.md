# بسته PHP آبان گیت وی

<div dir="rtl">

درگاه پرداخت کارت به کارت با تایید خودکار، برای PHP و لاراول. فاکتور بسازید، خریدار را به صفحه پرداخت بفرستید، و وقتی پول رسید با یک رویداد باخبر شوید.

*AbanGateway card-to-card payments for PHP and Laravel: create invoices, send buyers to the payment page, get a signed webhook when the money lands.*

- PHP 7.4 به بالا، فقط با cURL و بدون هیچ وابستگی دیگر
- لاراول ۱۰ تا ۱۳، خودکار: تنظیمات، فساد، مسیر آماده وبهوک و رویدادها
- مستندات کامل API: [github.com/AbanGateway/docs](https://github.com/AbanGateway/docs)

## نصب

```bash
composer require abangateway/abangateway-php-package
```

کلید API را از پنل آبان گیت وی بسازید: صفحه تنظیمات، بخش «کلیدهای API». کلید کامل فقط همان لحظه ساخت نشان داده میشود و با `ag_live_` یا `ag_test_` شروع میشود. کلید آزمایشی فاکتور آزمایشی میسازد و پولی جابه جا نمیشود.

## شروع سریع

```php
use AbanGateway\Client;

$aban = new Client(getenv('ABANGATEWAY_KEY'));

$invoice = $aban->invoiceForOrder('ORD-1043', [
    'amount_toman' => 150000,
    'callback_url' => 'https://shop.example/aban/webhook',
    'return_url'   => 'https://shop.example/orders/1043',
]);

if ($invoice->isPaid()) {
    // این سفارش قبلا پرداخت شده؛ دوباره نفروشید.
} else {
    header('Location: ' . $invoice->paymentUrl);
}
```

مبلغ را با `amount_toman` یا `amount_rial` بدهید، نه هر دو. همه مبلغ هایی که برمیگردند ریالی اند.

### چرا invoiceForOrder و نه createInvoice

هر دو هستند. ولی سفارش شما ممکن است از فاکتورش بیشتر عمر کند: خریدار فردا برمیگردد و فاکتور دیروز منقضی شده است. API برای شماره سفارش تکراری همان فاکتور قبلی را برمیگرداند، حتی اگر منقضی شده باشد. `invoiceForOrder` این را خودش حل میکند:

- فاکتور باز با همان مبلغ را دوباره میدهد.
- اگر مبلغ سفارش عوض شده باشد، فاکتور قبلی را لغو میکند و تازه میسازد.
- اگر قبلی منقضی یا لغو شده باشد، تلاش تازه میسازد: `ORD-1043#2`، `ORD-1043#3`.
- اگر سفارش در یکی از تلاش ها پرداخت شده باشد، همان را برمیگرداند تا دوباره پول نگیرید.

شماره سفارش خودتان همیشه با `baseOrderId()` برمیگردد.

## وبهوک، بدون فریم ورک

```php
use AbanGateway\Webhook;
use AbanGateway\Exception\SignatureVerificationException;

try {
    $event = Webhook::parse(
        file_get_contents('php://input'),
        $_SERVER['HTTP_X_SIGNATURE'] ?? '',
        getenv('ABANGATEWAY_WEBHOOK_SECRET')
    );
} catch (SignatureVerificationException $e) {
    http_response_code(401);
    exit;
}

// به خود وبهوک اعتماد نکنید؛ وضعیت را از API بپرسید.
$invoice = Webhook::confirm($aban, $event);

if ($invoice->isPaid()) {
    deliver_once($invoice->baseOrderId());   // اگر قبلا تحویل شده، کاری نکند
}
http_response_code(200);
```

کلید امضای وبهوک در پنل است: صفحه تنظیمات، بخش وبهوک، دکمه «ساخت کلید امضا». امضا روی بایت های خام بدنه است؛ JSON را قبل از چک امضا باز و دوباره نسازید.

وبهوک ممکن است بیش از یک بار برسد، مثلا وقتی پاسخ شما گم شود. کد تحویل باید این را تحمل کند: سفارشی که قبلا پرداخت شده علامت خورده، دوباره تحویل نشود.

## لاراول

بعد از نصب، چیزی را دستی ثبت نکنید. در `.env`:

```
ABANGATEWAY_KEY=ag_live_xxxx…xxxx
ABANGATEWAY_WEBHOOK_SECRET=...
```

مسیر `POST /abangateway/webhook` با اسم `abangateway.webhook` خودش ثبت میشود و بیرون از گروه web است، پس CSRF ندارد. آن را به عنوان `callback_url` بدهید:

```php
use AbanGateway\Laravel\Facades\AbanGateway;

$invoice = AbanGateway::invoiceForOrder((string) $order->id, [
    'amount_toman' => $order->total,
    'callback_url' => route('abangateway.webhook'),
    'return_url'   => route('orders.show', $order),
]);

return redirect()->away($invoice->paymentUrl);
```

مسیر وبهوک امضا را چک میکند، وضعیت را از API میپرسد و بر اساس جواب API یکی از این رویدادها را میفرستد: `InvoicePaid`، `InvoicePartiallyPaid`، `InvoiceExpired` و `InvoiceCancelled`، همه در فضای نام `AbanGateway\Laravel\Events`.

```php
use AbanGateway\Laravel\Events\InvoicePaid;
use Illuminate\Support\Facades\Event;

Event::listen(function (InvoicePaid $event) {
    $order = Order::findOrFail($event->orderId());
    if ($order->paid_at === null) {
        $order->update(['paid_at' => now()]);
        // تحویل سفارش
    }
});
```

اگر شنونده خطا بدهد، مسیر وبهوک کد ۵۰۰ برمیگرداند و آبان گیت وی خبر را بعدا دوباره میفرستد. برای همین شنونده باید تکرار را تحمل کند، مثل نمونه بالا.

تنظیمات را اگر خواستید منتشر کنید:

```bash
php artisan vendor:publish --tag=abangateway-config
```

پشتیبانی امنیتی لاراول ۱۰ تمام شده است. بسته روی آن کار میکند، ولی به روزرسانی به ۱۱ یا ۱۲ توصیه میشود.

## آزمایش بدون پول

با کلید `ag_test_`، فاکتورها آزمایشی اند و این متد پرداخت را شبیه سازی میکند و وبهوک واقعی میفرستد:

```php
$aban->simulatePayment($invoice->id);
```

## تایید یک باره

`verifyInvoice` فقط یک بار موفق میشود و بار بعد خطای `already_verified` میدهد. با آن میشود مطمئن شد دو درخواست همزمان یک سفارش را دو بار تحویل نمیدهند. در پنل هم فاکتور تایید شده نشان داده میشود.

## خطاها

همه خطاها از `AbanGateway\Exception\AbanGatewayException` ارث میبرند. شرط را روی کد بگذارید، نه روی متن:

```php
use AbanGateway\Exception\ApiException;

try {
    $invoice = $aban->createInvoice([...]);
} catch (ApiException $e) {
    $e->getErrorCode();   // مثلا insufficient_fee_wallet
    $e->getHttpStatus();  // مثلا 402
}
```

| کلاس | کی |
|---|---|
| `ConfigurationException` | کلید ناقص یا بدون پیشوند، مبلغ نادرست؛ پیش از هر درخواست |
| `AuthenticationException` | کلید پذیرفته نشد (۴۰۱) |
| `PermissionException` | مثلا `sandbox_only`: شبیه سازی با کلید زنده (۴۰۳) |
| `NotFoundException` | فاکتور پیدا نشد (۴۰۴) |
| `RateLimitException` | درخواست زیاد؛ `getRetryAfter()` ثانیه صبر کنید (۴۲۹) |
| `ServerException` | خطای سمت ما، مثل `capacity_full`؛ بعدا دوباره (۵xx) |
| `NetworkException` | پاسخی نرسید؛ ساخت دوباره با همان شماره سفارش امن است |
| `ApiException` | بقیه، مثل `insufficient_fee_wallet` (۴۰۲) یا `no_card_registered` (۴۰۹) |

جدول کامل کدها در [مستندات خطاها](https://github.com/AbanGateway/docs/blob/main/api/errors.md) است.

## پروانه

MIT

</div>
