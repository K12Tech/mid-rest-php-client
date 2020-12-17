<?php
namespace Sk\Mid\Tests;
use Exception;
use HRobertson\X509Verify\SslCertificate;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionException;
use Sk\Mid\AuthenticationResponseValidator;
use Sk\Mid\CertificateParser;
use Sk\Mid\Exception\MidInternalErrorException;
use Sk\Mid\Exception\MidNotMidClientException;
use Sk\Mid\HashType\Sha512;
use Sk\Mid\MobileIdAuthentication;
use Sk\Mid\Tests\Mock\TestData;

/**
 * Created by PhpStorm.
 * User: mikks
 * Date: 2/21/2019
 * Time: 3:46 PM
 */
class AuthenticationResponseValidatorTest extends TestCase
{

    /** @var AuthenticationResponseValidator $validator */
    private $validator;

    protected function setUp() : void
    {
        $this->validator = AuthenticationResponseValidator::newBuilder()
            ->withTrustedCaCertificatesFolder(__DIR__ . "/test_numbers_ca_certificates/")
            ->build();
    }

    /**
     * @test
     */
    public function validate_builderWithoutTrustedCertificates_shouldThrowException() {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("You need to set at least one trusted CA certificate to builder");

        AuthenticationResponseValidator::newBuilder()
            ->build();
    }

    /**
     * @test
     */
    public function validate_certificateIsNull_shouldThrowException() {
        $this->expectException(MidNotMidClientException::class);

        $authentication = $this->createMobileIdAuthenticationWithNullCertificate("OK", TestData::VALID_SIGNATURE_IN_BASE64);
        $this->validator->validate($authentication);
    }

    /**
     * @test
     */
    public function validate_NoTrustedRootCertificate_shouldThrowException() {
        $this->expectException(MidInternalErrorException::class);
        $this->expectExceptionMessage("Signer's certificate not trusted");

        $authentication = MobileIdAuthentication::newBuilder()
            ->withResult("OK")
            ->withSignatureValueInBase64(TestData::VALID_SIGNATURE_IN_BASE64)
            ->withCertificate(CertificateParser::parseX509Certificate(TestData::RANDOM_CERTIFICATE))
            ->withSignedHashInBase64(TestData::SIGNED_HASH_IN_BASE64)
            ->withHashType(new Sha512())
            ->build();

        $this->validator->validate($authentication);
    }

    /**
     * @test
     */
    public function validate_whenRSA_shouldReturnValidAuthenticationResult()
    {
        $authentication = $this->createValidMobileIdAuthentication();
        $authenticationResult = $this->validator->validate($authentication);

        $this->assertEquals(true, $authenticationResult->isValid());
        $this->assertEquals(true, count($authenticationResult->getErrors()) == 0);
    }

    /**
     * @test
     */
    public function validate_whenECC_shouldReturnValidAuthenticationResult()
    {
        $authentication = $this->createMobileIdAuthenticationWithECC();
        $authenticationResult = $this->validator->validate($authentication);

        $this->assertEquals(true, $authenticationResult->isValid());
        $this->assertEquals(true, count($authenticationResult->getErrors()) == 0);
    }

    /**
     * @test
     */
    public function validate_whenResultLowerCase_shouldReturnValidAuthenticationResult()
    {
        $authentication = MobileIdAuthentication::newBuilder()
            ->withResult("OK")
            ->withSignatureValueInBase64(TestData::VALID_SIGNATURE_IN_BASE64)
            ->withCertificate(CertificateParser::parseX509Certificate(TestData::AUTH_CERTIFICATE_EE))
            ->withSignedHashInBase64(TestData::SIGNED_HASH_IN_BASE64)
            ->withHashType(new Sha512())
            ->build();

        $authenticationResult = $this->validator->validate($authentication);
        $this->assertEquals(true, $authenticationResult->isValid());
        $this->assertEquals(true, count($authenticationResult->getErrors()) == 0);
    }

    /**
     * @test
     */
    public function validate_whenResultNotOk_shouldReturnInvalidAuthenticationResult()
    {
        $this->expectException(MidInternalErrorException::class);

        $authentication = $this->createMobileIdAuthenticationWithInvalidResult();
        $authenticationResult = $this->validator->validate($authentication);

        $this->assertEquals(false, $authenticationResult->isValid());
        $this->assertEquals(true, in_array("Response result verification failed", $authenticationResult->getErrors()));
    }

    /**
     * @test
     */
    public function validate_shouldReturnValidIdentity()
    {
        $authentication = $this->createValidMobileIdAuthentication();
        $authenticationResult = $this->validator->validate($authentication);

        $this->assertTrue($authenticationResult->isValid());
        $this->assertEquals("MARY ÄNN", $authenticationResult->getAuthenticationIdentity()->getGivenName());
        $this->assertEquals("O’CONNEŽ-ŠUSLIK TESTNUMBER", $authenticationResult->getAuthenticationIdentity()->getSurName());
        $this->assertEquals("60001019906", $authenticationResult->getAuthenticationIdentity()->getIdentityCode());
        $this->assertEquals("EE", $authenticationResult->getAuthenticationIdentity()->getCountry());
    }

    /**
     * @test
     */
    public function validate_withTrustedCaCertificate_shouldReturnValidIdentity()
    {
        $certsPath = __DIR__ . "/test_numbers_ca_certificates/";
        $validatorBuilder = AuthenticationResponseValidator::newBuilder();
        foreach (array_diff(scandir($certsPath), array('.', '..')) as $file) {
            $caCertificate = file_get_contents($certsPath .$file);

            $validatorBuilder->withTrustedCaCertificate($caCertificate);
        }
        $validator = $validatorBuilder->build();


        $authentication = $this->createValidMobileIdAuthentication();
        $authenticationResult = $this->validator->validate($authentication);

        $this->assertTrue($authenticationResult->isValid());
        $this->assertEquals("MARY ÄNN", $authenticationResult->getAuthenticationIdentity()->getGivenName());
        $this->assertEquals("O’CONNEŽ-ŠUSLIK TESTNUMBER", $authenticationResult->getAuthenticationIdentity()->getSurName());
        $this->assertEquals("60001019906", $authenticationResult->getAuthenticationIdentity()->getIdentityCode());
        $this->assertEquals("EE", $authenticationResult->getAuthenticationIdentity()->getCountry());
    }

    /**
     * @test
     */
    public function validate_certWithBeginAndEndCert_shouldReturnValidIdentity()
    {
        $validAuthentication = MobileIdAuthentication::newBuilder()
                ->withResult("OK")
                ->withSignatureValueInBase64(TestData::VALID_SIGNATURE_IN_BASE64)
                ->withCertificate(CertificateParser::parseX509Certificate("-----BEGIN CERTIFICATE-----".TestData::AUTH_CERTIFICATE_EE."-----END CERTIFICATE-----"))
                ->withSignedHashInBase64(TestData::SIGNED_HASH_IN_BASE64)
                ->withHashType(new Sha512())
                ->build();


        $authenticationResult = $this->validator->validate($validAuthentication);

        $this->assertTrue($authenticationResult->isValid());
        $this->assertEquals("MARY ÄNN", $authenticationResult->getAuthenticationIdentity()->getGivenName());
        $this->assertEquals("O’CONNEŽ-ŠUSLIK TESTNUMBER", $authenticationResult->getAuthenticationIdentity()->getSurName());
        $this->assertEquals("60001019906", $authenticationResult->getAuthenticationIdentity()->getIdentityCode());
        $this->assertEquals("EE", $authenticationResult->getAuthenticationIdentity()->getCountry());
    }

    /**
     * @test
     */
    public function validate_whenCertificateIsNull_shouldThrowException()
    {
        $this->expectException(MidNotMidClientException::class);

        $authentication = MobileIdAuthentication::newBuilder()
            ->withResult("OK")
            ->withSignatureValueInBase64(TestData::VALID_SIGNATURE_IN_BASE64)
            ->withCertificate(null)
            ->withSignedHashInBase64(TestData::SIGNED_HASH_IN_BASE64)
            ->withHashType(new Sha512())
            ->build();

        $this->validator->validate($authentication);
    }

    /**
     * @test
     */
    public function validate_whenHashTypeIsNull_shouldThrowException()
    {
        $this->expectException(MidInternalErrorException::class);

        $authentication = MobileIdAuthentication::newBuilder()
            ->withResult("OK")
            ->withSignatureValueInBase64(TestData::VALID_SIGNATURE_IN_BASE64)
            ->withCertificate(CertificateParser::parseX509Certificate(TestData::AUTH_CERTIFICATE_EE))
            ->withSignedHashInBase64(TestData::SIGNED_HASH_IN_BASE64)
            ->withHashType(null)
            ->build();

        $this->validator->validate($authentication);
    }



    private function createValidMobileIdAuthentication()
    {
        return $this->createMobileIdAuthentication("OK", TestData::VALID_SIGNATURE_IN_BASE64);
    }

    private function createMobileIdAuthenticationWithInvalidResult()
    {
        return $this->createMobileIdAuthentication("NOT OK", TestData::VALID_SIGNATURE_IN_BASE64);
    }

    private function createMobileIdAuthentication($result, $signatureInBase64)
    {
        return MobileIdAuthentication::newBuilder()
            ->withResult($result)
            ->withSignatureValueInBase64($signatureInBase64)
            ->withCertificate(CertificateParser::parseX509Certificate(TestData::AUTH_CERTIFICATE_EE))
            ->withSignedHashInBase64(TestData::SIGNED_HASH_IN_BASE64)
            ->withHashType(new Sha512())
            ->build();
    }

    private function createMobileIdAuthenticationWithNullCertificate($result, $signatureInBase64) {
        try {
            return MobileIdAuthentication::newBuilder()
                ->withResult($result)
                ->withSignatureValueInBase64($signatureInBase64)
                ->withCertificate(null)
                ->withSignedHashInBase64(TestData::SIGNED_HASH_IN_BASE64)
                ->withHashType(new Sha512())
                ->build();
        } catch (ReflectionException $e) {
            return $e;
        }
    }

    private function createMobileIdAuthenticationWithECC()
    {
        try {
            return MobileIdAuthentication::newBuilder()
                ->withResult("OK")
                ->withSignatureValueInBase64(TestData::VALID_ECC_SIGNATURE_IN_BASE64)
                ->withCertificate(CertificateParser::parseX509Certificate(TestData::ECC_CERTIFICATE))
                ->withSignedHashInBase64(TestData::SIGNED_ECC_HASH_IN_BASE64)
                ->withHashType(new Sha512())
                ->build();
        } catch (ReflectionException $e) {
            return $e;
        }
    }






}

