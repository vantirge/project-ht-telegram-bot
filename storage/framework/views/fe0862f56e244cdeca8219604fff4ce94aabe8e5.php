<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Код авторизации</title>
</head>
<body>
    <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">
        <h2>Код авторизации для Telegram бота</h2>
        <p>Ваш код авторизации: <strong style="font-size: 24px; color: #007bff;"><?php echo e($code); ?></strong></p>
        <p>Введите этот код в Telegram боте для завершения авторизации.</p>
        <p style="color: #6c757d; font-size: 14px;">Код действителен в течение 10 минут.</p>
    </div>
</body>
</html> <?php /**PATH C:\Users\123\Desktop\bot\resources\views/emails/auth-code.blade.php ENDPATH**/ ?>