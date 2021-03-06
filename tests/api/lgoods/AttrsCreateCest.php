<?php
namespace goods;
use \ApiTester;
use Codeception\Util\Debug;


class AttrsCreateCest
{
    public function _before(ApiTester $I){ $I->loginAdmin();
    }

    public function _after(ApiTester $I)
    {
    }

    // tests
    public function tryToTest(ApiTester $i)
    {
        $i->setAuthHeader();$i->sendPOST("/lattr", [
            [
                'a_name' => '尺寸',
                'a_type' => 2,
            ],
            [
                'a_name' => '颜色',
                'a_type' => 2,
            ]
        ]);
        $i->seeResponseCodeIs(200);
        $i->seeResponseContainsJson([
            'code' => 0
        ]);
        $res = $i->grabResponse();
        Debug::debug(json_decode($res, true));
    }
}
