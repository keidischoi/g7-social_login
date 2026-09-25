<?php

namespace Plugins\G7\SocialLogin\Support;

/**
 * 카카오/구글 공식 브랜드 아이콘을 data URI로 제공한다.
 *
 * 별도 정적 자산 서빙 라우트를 만들지 않고(이 플러그인은 프론트 JS 번들이
 * 없다) `resources/images/`의 실제 파일을 그대로 base64 인코딩해 레이아웃
 * JSON의 `<img src>`에 인라인한다. 원본 파일:
 * - kakao-symbol.png: developers.kakao.com/tool/resource/login의 공식
 *   "완성형/Large" 로그인 버튼 PNG에서 말풍선 심볼 영역만 크롭 + 배경 투명화
 *   (형태·비율 변경 없음, 배경색만 제거).
 * - google-icon.png: developers.google.com/identity/branding-guidelines의
 *   공식 배포 ZIP(signin-assets.zip) 중 Light 테마·Show text=No·Square,
 *   Android+Web @2x PNG 원본 그대로(무변경).
 * - naver-icon.png: 네이버 공식 에셋을 내려받지 못한 환경에서 만든 임시
 *   플레이스홀더(브랜드 컬러 #03C75A 배경 + 흰색 "N")다. 실제 배포 전에는
 *   developers.naver.com > 오픈API 이용 신청 > "로그인 버튼 만들기"에서
 *   제공하는 공식 로그인 버튼/심볼 이미지로 반드시 교체할 것.
 */
class BrandIcons
{
    public static function kakaoDataUri(): string
    {
        return self::dataUri('kakao-symbol.png');
    }

    public static function googleDataUri(): string
    {
        return self::dataUri('google-icon.png');
    }

    public static function naverDataUri(): string
    {
        return self::dataUri('naver-icon.png');
    }

    private static function dataUri(string $filename): string
    {
        $path = __DIR__.'/../../resources/images/'.$filename;

        return 'data:image/png;base64,'.base64_encode(file_get_contents($path));
    }
}
