<?php

/*
 * This file is part of the FileGator package.
 *
 * (c) Milos Stojanovic <alcalbg@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE file
 */

namespace Filegator\Controllers;

use Filegator\Config\Config;
use Filegator\Kernel\Request;
use Filegator\Kernel\Response;
use Filegator\Services\Auth\AuthInterface;
use Filegator\Services\Tmpfs\TmpfsInterface;
use Filegator\Services\Logger\LoggerInterface;
use Rakit\Validation\Validator;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class AuthController
{
    protected $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }
    public function sendVerificationEmail($email, $code)
    {
        $mail = new PHPMailer(true);
        try {
            // Server ayarları
            $mail->isSMTP();
            $mail->Host = 'smtp.gmail.com'; // SMTP sunucusu
            $mail->SMTPAuth = true;
            $mail->Username = 'akkanali950@gmail.com'; // SMTP kullanıcısı
            $mail->Password = 'xfsdmxkfiybjwlte'; // SMTP şifresi
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = 587;

            $this->logger->log("");

            // Alıcı ve Gönderici bilgileri
            $mail->setFrom($email, 'firegator');
            $mail->addAddress($email);

            // İçerik
            $mail->isHTML(true);
            $mail->Subject = 'Your Verification Code';
            $mail->Body = "Your verification code is: $code";

            $mail->send();
        } catch (Exception $e) {
            // Hata durumunda işlem yap
            error_log("Message could not be sent. Mailer Error: {$mail->ErrorInfo}");
        }
    }
    public function login(Request $request, Response $response, AuthInterface $auth, TmpfsInterface $tmpfs, Config $config)
    {
        $username = $request->input('username');
        $password = $request->input('password');
        $ip = $request->getClientIp();

        $lockfile = md5($ip).'.lock';
        $lockout_attempts = $config->get('lockout_attempts', 5);
        $lockout_timeout = $config->get('lockout_timeout', 15);

        // Giriş denemelerini temizle
        foreach ($tmpfs->findAll($lockfile) as $flock) {
            if (time() - $flock['time'] >= $lockout_timeout) {
                $tmpfs->remove($flock['name']);
            }
        }

        // Çok fazla giriş denemesi
        if ($tmpfs->exists($lockfile) && strlen($tmpfs->read($lockfile)) >= $lockout_attempts) {
            $this->logger->log("Too many login attempts for {$username} from IP ".$ip);

            return $response->json('Not Allowed', 429);
        }

        // Kullanıcı adı ve şifre doğruysa
        if ($auth->checkUser($username, $password)) {
            // Doğrulama kodu üret ve geçici dosya sistemine yaz
            
            $code = rand(100000, 999999); // 6 haneli doğrulama kodu
            if($tmpfs->exists($username.'_2fa'))//eskiden girilmemiş code varsasil
            {
                $tmpfs->remove($username.'_2fa');
            }
            $tmpfs->write($username.'_2fa', ['code' => $code], true);
// Kullanıcının doğrulama kodunu geçici olarak sakla
            $this->logger->log($auth->user()->getEmail());
            // Kullanıcıya doğrulama kodunu e-posta ile gönder
            $this->sendVerificationEmail($auth->user()->getEmail(), $code);
            
            $this->logger->log("Logged in {$username} from IP ".$ip);
            //$auth->forget();
            // Şu anda kullanıcı sadece username ve şifre ile doğrulandı, ama henüz oturum açmadı
            return $response->json('Verification code sent, please verify', 200);
        }

        // Giriş başarısız olduysa
        $this->logger->log("Login failed for {$username} from IP ".$ip);
        $tmpfs->write($lockfile, 'x', true); // Başarısız denemeyi kaydet

        return $response->json('Login failed, please try again', 422);
    }


    public function logout(Response $response, AuthInterface $auth)
    {
        return $response->json($auth->forget());
    }

    public function getUser(Response $response, AuthInterface $auth)
    {
        $user = $auth->user() ?: $auth->getGuest();

        return $response->json($user);
    }

    public function changePassword(Request $request, Response $response, AuthInterface $auth, Validator $validator)
    {
        $validator->setMessage('required', 'This field is required');
        $validation = $validator->validate($request->all(), [
            'oldpassword' => 'required',
            'newpassword' => 'required',
        ]);

        if ($validation->fails()) {
            $errors = $validation->errors();

            return $response->json($errors->firstOfAll(), 422);
        }

        if (! $auth->authenticate($auth->user()->getUsername(), $request->input('oldpassword'))) {
            return $response->json(['oldpassword' => 'Wrong password'], 422);
        }

        return $response->json($auth->update($auth->user()->getUsername(), $auth->user(), $request->input('newpassword')));
    }
    public function verify2fa(Request $request, Response $response, TmpfsInterface $tmpfs, AuthInterface $auth)
{
    $username = $auth->user()->getUsername();
    $input_code = $request->input('code');
    $stored_codes = $tmpfs->read($username.'_2fa');

    // Assuming stored_codes is a comma-separated string of codes or an array
    if (is_string($stored_codes)) {
        $stored_codes = explode(',', $stored_codes); // Convert to an array if needed
    }

    if (in_array($input_code, $stored_codes)) {
        // Kullanıcı doğrulandı, oturum aç
        //$auth->setUser($auth->user()); // Kullanıcı oturumu aç
        $auth->authenticate($username, $input_code);//password içine ne yazıldığının önemi yok
        $tmpfs->remove($username.'_2fa'); // Geçici 2FA kodunu kaldır

        return $response->json($auth->user()); // Ana sayfaya yönlendirme
    } else {
        $auth->forget();//kullanıyı unut
        $this->logger->log('Input code: ' . $input_code);
        $this->logger->log('Stored codes: ' . json_encode($stored_codes));
        return $response->json('Invalid verification code', 400);
    }
}


}