<?php


require __DIR__ . "/../vendor/autoload.php";
use Jose\Factory\JWKFactory;
use Jose\Factory\JWSFactory;
use Jose\Factory\JWEFactory;
use Jose\Loader;

class auth_oauth2_composer_testcase extends basic_testcase{

    public function test_create_jwk() {
        $jwk = JWKFactory::createKey([
            'kty' => 'RSA',
            'size' => 4096,
            'kid' => 'KEYtest1',
            'alg' => 'RSA-OEP',
            'use' => 'enc',
        ]);

        //For log function
        $tmp = 'test_create_jwk() LOG: ';
        fwrite(STDERR, print_r($tmp . print_r($jwk,TRUE) , TRUE));

        $this->assertNotNull($jwk);
        $this->assertTrue($jwk->has('kty'));
        $this->assertTrue($jwk->has('kid'));
        $this->assertTrue($jwk->has('alg'));
        $this->assertTrue($jwk->has('use'));
    }

    public function test_create_keyset() {
        $jwks = JWKFactory::createStorableKeySet ('./test.keyset', 
            [
                'kty' => 'RSA',
                'size' => 4096,
                'alg' => 'RSA-OAEP',
                'use' => 'enc',
            ],
            3
        );

       //For log function
       $tmp = 'test_create_keyset() LOG: ';
       fwrite(STDERR, print_r($tmp . print_r($jwks,TRUE) , TRUE));

       $this->assertNotNull($jwks);
       $this->assertCount(3, $jwks);
    }

    public function test_create_jws() {
        $jws = JWSFactory::createJWS([
            'iss' => 'Test Server',
            'aud' => 'Client',
            'sub' => 'Resource owner',
            'exp' => time()+3600,
            'iat' => time(),
            'nbf' => time(),
        ]);

       //For log function
       $tmp = 'test_create_jws() LOG: ';
       fwrite(STDERR, print_r($tmp . print_r($jws,TRUE) , TRUE));

        $this->assertNotNull($jws);
        $this->assertCount(6, $jws->getPayload() );
    }    

    public function test_create_jwe() {
        $jwe = JWEFactory::createJWE(
            'my important message',
            [
                'enc' => 'A256CBC-HS512',
                'alg' => 'RSA-OAEP-256',
                'zip' => 'DEF',
            ]
        );

       //For log function
       $tmp = 'test_create_jwe() LOG: ';
       fwrite(STDERR, print_r($tmp . print_r($jwe,TRUE) , TRUE));

       $this->assertNotNull($jwe);
       $this->assertCount(3, $jwe->getSharedProtectedHeaders() );
    }

    // ------ Operations Test ---------

    public function test_sign(){
        $key = JWKFactory::createKey([
            'kty' => 'RSA',
            'size' => 4096,
            'kid' => 'TestSignKey1',
            'alg' => 'RS256',
            'use' => 'sig'
        ]);
        
        $claims = [
            'nbf' => time(),
            'iat' => time(),
            'exp' => time() + 3600,
            'iss' => 'Me',
            'aud' => 'You',
            'sub' => 'My friend',
            'is_root' => true
        ];

        $jws = JWSFactory::createJWSToCompactJSON(
            $claims,
            $key,
            [
                'crit' => ['exp', 'aud'],
                'alg' => 'RS256'
            ]
            );

        //For log function
       $tmp = 'test_sign() LOG: ';
       fwrite(STDERR, print_r($tmp . print_r($jws,TRUE) , TRUE));

       $jwsarray = explode('.', $jws);
       $this->assertNotNull($jws);
       $this->assertCount(3, $jwsarray );
    }

    public function test_verify_signatures() {
        $jwk_set = JWKFactory::createFromJKU('https://www.googleapis.com/oauth2/v3/certs');
        $loader = new Loader();

        $input = 'eyJhbGciOiJSUzI1NiJ9.eyJuYmYiOjE0NTE0NjkwMTcsImlhdCI6MTQ1MTQ2OTAxNywiZXhwIjoxNDUxNDcyNjE3LCJpc3MiOiJNZSIsImF1ZCI6IllvdSIsInN1YiI6Ik15IGZyaWVuZCJ9.mplHfnyXzUdlEkPmykForVM0FstqgiihfDRTd2Zd09j6CZzANBJbZNbisLerjO3lR9waRlYvhnZu_ewIAahDwmVTfpSeKKABbAyoTHXTH2WLgMPLtOAsoausUf584eAAj_kyldIOV8a83Qz1NztZHVD3DbGTiCN0BOj-qnc65yQmEDEYK5cxG1xC22YK5aohZ3xm8ixwNZpxYr8cNOkauASYjPGODbHqY_gjQ-aKA21kxbYgwM6mDYSc3QRej1_3m6bD3jKPsK4jv3yzosVMEXOparf4sEb8q_zCPMDJAJgZZ8VICwJdgYnJkQuIutS-w3_iT-riKl8fkgmJezQVkg';
        
        $signature_index = NULL;
        
        $jws = $loader->loadAndVerifySignatureUsingKeySet( $input, $jwk_set, ['RS256'], $signature_index);

       //For log function
       $tmp = 'test_verify_signatures() LOG: ';
       fwrite(STDERR, print_r($tmp . print_r($jwk_set,TRUE) , TRUE));

       $this->assertNotNull($signature_index);
    }

}