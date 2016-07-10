eMQ
===

eMQ is a simple eMailQueue to deal with e-mail quotas imposed on you by a shared hosting plan, for example.


###Description

If you are on shared hosting and need to send a larger amount of e-mails to the
users of your website, you might run into quota issues (your host might limit you
to 200 emails / hour or something similar). I wrote this little class to help with
this problem. Add all the e-mails you need to send to the queue, and run the cron
script via a task scheduler, like cron. eMQ will stagger your e-mails so you stay
within your quota.

Obviously, if you need to send tons and tons of e-mails, all the time, this is not for
you, and you should not do this from a shared hosting plan, anyway. But for a few
thousand, every once in a while, this should work well.

Don't use it to spam, please!


###Requirements

**eMQ** uses [RedBeanPHP](redbeanphp.com) and SQLite for storage. I added an instantiable
version (RedBean_Instance.php) of the Facade (Facade.php) to the latest version (4.3.2) of
RedBeanPHP, inspired by [daviddeusch's](https://github.com/daviddeutsch/redbean-instance)
seemingly unmaintained project, to keep the DB access selfcontained.


###Usage

```php
require_once("c_eMQ.php");
$eq=new c_eMQ();

$priority=5;

$eq->addMail("email@destination.com","Subject","This one can wait a little until it gets sent.",$priority);

$priority=1;

$eq->addMail("email@destination.com","Important","We'll try to send this right away.",$priority);
```

Add *cron_script.php* to your task scheduler to run once / minute or so, or call

```php
$eq->sendBatch();
```
from within your own script.


###License etc.
MIT License

It'd be cool if you'd let me know if you find this useful and what you use it for!


