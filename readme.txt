=== BrandTalk (브랜드톡) ===
Contributors: todaymeal
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 0.6.1
License: GPLv2 or later

신뢰도 기반 별점·리뷰 서비스의 데이터/REST 레이어.

== 아키텍처 (v0.5.0 재작성) ==
HivePress 1.7.x 구조를 참고해 처음부터 재구성:

* includes/class-core.php   — 싱글턴 코어. `brandtalk()` 전역 함수, PSR식 오토로더
                              (`\BrandTalk\Models\Reaction` → includes/models/class-reaction.php),
                              activate/update 훅(`brandtalk/v1/activate`, `brandtalk/v1/update`).
* includes/helpers.php      — `BrandTalk\Helpers` (bt\prefix, bt\merge_arrays, bt\rest_response …)
* includes/configs/*.php    — post-types / taxonomies / settings / meta-boxes 를 배열로 정의,
                              `brandtalk/v1/{config}` 필터로 확장 가능.
* includes/components/*.php — 자동 인스턴스화되는 컴포넌트(생성자에서 훅 등록):
                              post-type, review, reaction, follow, verification, trust,
                              installer, router, admin.
* includes/models/*.php     — 커스텀 테이블 경량 액티브레코드 + DDL(schema()).
* includes/controllers/*.php — 라우트 정의(`routes` 배열) → Router 컴포넌트가 REST 등록.
* includes/class-options.php — 정책 설정 접근자(단일 옵션 `brandtalk_options`).

기능·정책은 v0.4.0 과 동일하게 유지. 기존 슬러그/테이블/옵션/REST 네임스페이스 보존:
`brandtalk_review`, `brandtalk_category`, `brandtalk_rating`, `brandtalk_options`,
`wp_brandtalk_{reactions,follows,verifications,trust_log}`, `brandtalk/v1`.

== 근거 문서 ==
* 브랜드톡 기능정의서 v0.3 (A~S)
* 브랜드톡 정책정의서 v0.2 (§13.1 포함)

== 기능 ==
* 리뷰 CPT + 계층형 카테고리(시드: 맛집/영화/음악/뉴스) + 별점 메타(1~5 필수, §2.1.4)
* 커스텀 테이블 4종: reactions / follows / verifications / trust_log
* 신뢰도 엔진: 좋아요 +1 / 싫어요 -1 / 신고 -(5~10) / 팔로워 +1 (§4.2),
  리뷰어 신용도 가중치 -0.2~+0.2 — 반응자가 남긴 좋아요/싫어요는 (±1 ± 반응자 신용도)로 반영 (§4.2.7),
  신뢰 판정 비율 > 0.51 (§4.3), 레벨 5단계 (§4.4)
* §13.1 적용 범위(Post): 설정 화면에서 포스트 타입 체크박스로 연동,
  연동 포스트 타입에 "브랜드톡 카테고리" + "브랜드톡 별점" 노출
* §13.1.3 프론트 노출: 연동 포스트 타입의 글에 brandtalk_rating(1~5)이 있으면
  본문·발췌문에 별점 블록을 자동 표시. 숏코드 [brandtalk_rating], 관리자 글 목록 컬럼.
* 하위 별점 항목(Criterion): 재사용 가능한 하위 평가 항목(예: 맛·가격·분위기).
  - `brandtalk_criterion` 택소노미 — 리뷰 › "하위 별점 항목" 에서 추가/편집/삭제
  - 브랜드톡 카테고리 편집화면에서 체크박스로 연결 (텀 메타 brandtalk_criteria, 순서 보존)
  - 같은 항목을 여러 카테고리에서 공유 가능
  - 리뷰/글의 별점 메타박스에 카테고리별 하위 항목 점수 입력, 프론트에 함께 표시
* §11.3 설정 가능 정책값: 신고 감점(5~10) / 신뢰 임계값 / 랭킹 상한 / 지역 잠금 — 설정 화면 제공

== REST API (brandtalk/v1) ==
응답 봉투(HivePress 규약): 성공 `{ "data": … }` / 실패 `{ "error": { "code": N, "errors": [ … ] } }`

공개(비로그인):
  GET    /auth/providers
  GET    /categories                    (각 카테고리에 연결된 criteria 포함)
  GET    /criteria                       (하위 별점 항목 전체; ?category=slug|id 로 필터)
  GET    /reviews            (category, author, orderby=date|rating, order, page, per_page)
  GET    /reviews/{id}
  GET    /users/{id}/trust
  GET    /rankings           (type=my|friends)   ※ friends 는 로그인 필요
  GET    /recommendations

로그인 필수:
  POST   /reviews                       (rating*, category*, content, image_id, criteria{criterion_id:1~5})
  POST   /reviews/{id}/reactions        (type = like|dislike|report)
  DELETE /reviews/{id}/reactions
  POST   /users/{id}/follow
  DELETE /users/{id}/follow
  GET    /verifications
  POST   /verifications                 (type = region|friend|social|info|category, payload)

== 액션 훅 ==
* brandtalk/v1/activate, brandtalk/v1/update, brandtalk/v1/deactivate, brandtalk/v1/setup
* brandtalk/v1/review/published ( $post_id, $user_id )      — §9.6 팔로워 노티 연결점
* brandtalk/v1/reaction/changed ( $review_id, $user_id, $type )
* brandtalk/v1/follow/created | follow/deleted ( $following_id, $follower_id )
* brandtalk/v1/verification/submitted | verification/reviewed
* brandtalk/v1/trust/recalculated ( $user_id, $score, $level )
* brandtalk/v1/rating_html ( $html, $post_id, $rating ) — 필터: 프론트 별점 HTML
* brandtalk/v1/rating_position ( $position, $post_id ) — 필터: before|after (기본 before)
* brandtalk/v1/category/criteria_updated ( $category_term_id, $criterion_ids )

== 미포함 (범위 밖) ==
* 소셜 로그인 OAuth 흐름 구현 (제공자 목록·키 스키마는 포함)
* 관리자 리뷰·신고·신뢰도 관리 화면
* HivePress 의 필드/블록/템플릿/라우팅(프론트) DSL — 데이터·REST 레이어에 불필요하여 생략

== 미확정 (정책정의서 제5부, 승인 대기) ==
신뢰도 레벨 구간·강등 임계·완화 산식, 신고 차등 감점, 랭킹/추천 가중 고도화,
추천 컨텐츠 외부 소스, "WordPress" 로그인 해석, 블록체인 방지, 지인 인증 payload,
리뷰 사진/글 필수 여부, 노티 발송 채널, REST 토큰 인증·Scope·레이트리밋.

== Changelog ==
= 0.6.1 =
* 관리자 "브랜드톡 › 리뷰어" 목록 화면 신설. 리뷰 CPT 또는 브랜드톡 별점이 등록된 글을
  1건 이상 작성한 사용자별로 ID / 이메일 / 닉네임 / 리뷰 수 / 리뷰 평균 점수 / 순 좋아요 /
  신용도(§4.2.7) / 신뢰도 점수·레벨 / 팔로워 를 한 표에 보여준다.
  - "리뷰" 집계 범위 = 리뷰 CPT + brandtalk_rating / brandtalk_criteria_ratings 메타가 있는
    모든 포스트 타입의 글(글·페이지·향후 추가 CPT 포함, attachment 제외). 리뷰 수·평균은 전체 기준,
    순 좋아요·신용도·신뢰도 점수는 리뷰 CPT 반응 기준(§4.2).
  - 컬럼 정렬(리뷰 수 DESC 기본) + 페이지네이션(30/쪽). 리뷰 수 → 해당 작성자 리뷰 목록,
    이메일 → 사용자 편집 화면 링크. 권한 `list_users`.
  - 집계는 SQL 한 번(리뷰 집계 + 순 좋아요 서브쿼리 + 신뢰도 유저메타 조인) + 팔로워 배치 조회.
  - `Reviewers_List` 컴포넌트. §11.2.6 "사용자·신뢰도 관리"의 조회 부분.

= 0.6.0 =
* 리뷰어 신용도 가중치(§4.2.7). 리뷰어의 리뷰가 받은 순 좋아요(좋아요−싫어요)를 구간으로 나눠
  개인 신용도를 -0.2 ~ +0.2 (0.1 단위)로 매긴다: 순 좋아요 ≥ 상위기준 → +0.2, ≥ 하위기준 → +0.1,
  |순 좋아요| < 하위기준 → 0, ≤ -하위기준 → -0.1, ≤ -상위기준 → -0.2 (기본 하위 5 / 상위 20).
  그 리뷰어가 다른 리뷰에 누른 좋아요/싫어요는 ±1 이 아니라 (±1 ± 그 리뷰어의 신용도)로
  게시자 신뢰도에 반영된다. 신뢰도 점수는 이제 소수(2자리) 값을 가진다.
  - 설정: `리뷰 › 설정 › 신뢰도·정책 값` 에 "신용도 ±0.1 기준" · "신용도 ±0.2 기준"(순 좋아요) 추가.
  - 유저 메타 `brandtalk_trust_credibility`. REST `GET /users/{id}/trust` · 리뷰 응답 `author.trust` 에 `credibility` 포함.
  - `Trust::credibility_for_net()` / `recalc_credibility()` / `credibility_weight()`,
    `Reaction::net_likes_for_author()` / `weighted_counts_for_author()`.
  - 반응자 신용도는 각자의 캐시값을 재계산 시점에 읽는다(지연 정합 — 반응자 신용도가 바뀌면
    그가 반응했던 게시자 점수는 다음 재계산 때 반영).

= 0.5.9 =
* 프론트 "나도 리뷰하기" 폼에 하위 별점 항목 입력 추가. 브랜드톡 카테고리는 대상 글에서
  상속해 텍스트로만 표시하고 선택할 수 없다. 그 카테고리에 연결된 하위 별점 항목별로
  1~5 별점을 매길 수 있고(`criteria[<id>]`), 종합 별점과 함께 리뷰에 저장된다.
  리뷰 카드에도 하위 별점 항목별 점수를 함께 표시한다.

= 0.5.8 =
* 프론트 리뷰. 연동 포스트 타입의 글(브랜드톡 카테고리가 있거나 리뷰가 있는 글)에 리뷰 섹션을
  본문 뒤에 노출한다. 다른 사용자가 리뷰 목록을 보고 좋아요/나빠요를 누를 수 있고(구독자 포함,
  자기 리뷰 제외), `edit_posts` 권한이 있는 비작성자는 "나도 리뷰하기"로 종합 별점 + 간단한
  텍스트 + 이미지(최대 5장)를 올릴 수 있다. 카테고리는 대상 글에서 상속. 한 사용자당 한 글에
  리뷰 1개.
  - REST: `POST /reviews` 에 `target`(대상 글) 파라미터 + `images[]` 멀티파트 업로드(제어된
    업로더 — 구독자 권한 없이, 이미지 MIME만, 리뷰에 첨부한 것만 생성, 리뷰 삭제 시 함께 삭제).
    권한은 `edit_posts`(구독자 제외). `GET /reviews` 에 `target` 필터. 응답에 `images` 배열.
  - `POST /reviews/{id}/reactions` — 자기 리뷰 반응 차단(403).
  - `[brandtalk_reviews]` 숏코드. `Frontend::render_reviews()` / `Review::reviews_for_target()` /
    `Review::existing_review()`.

= 0.5.7 =
* 리뷰 대상 연결. 리뷰(brandtalk_review)는 제목을 직접 입력하지 않고, "리뷰 대상" 메타박스에서
  기존 글 1개를 포스트타입별 드롭다운으로 선택한다. 이미 다른 리뷰에 연결됐거나 §13.1 별점
  (brandtalk_rating / brandtalk_criteria_ratings)이 등록된 글은 목록에서 제외된다.
  리뷰 제목은 연결된 글의 제목으로 자동 저장되며, 대상 글의 제목이 바뀌면 리뷰 제목도 따라 갱신된다.
  포스트 메타 `brandtalk_target`(대상 글 ID). REST 리뷰 응답에 `title` + `target{id,type,title,url}` 추가.
  meta-boxes 컨피그에 `attach_enabled=>false`(연동 포스트타입에 붙이지 않는 박스) 플래그 지원.

= 0.5.6 =
* 글 편집화면에서 브랜드톡 카테고리를 선택하면 하위 별점 항목이 즉시 나타나도록 수정
  (이전에는 저장 후 새로고침해야 표시됨). 카테고리에 연결된 모든 항목을 렌더링해 두고
  현재 선택된 카테고리의 항목 행만 표시·활성화한다. 선택이 바뀌면 인라인 스크립트가
  즉시 토글(블록 편집기: core/editor 구독, 클래식: 카테고리 체크리스트 감시).
  숨겨진 행은 disabled 라 저장 시 전송되지 않아 다른 카테고리 항목이 섞이지 않는다.

= 0.5.5 =
* "브랜드톡" 목록 화면의 "Array to string conversion" / "headers already sent" 경고 수정.
  통합 목록을 위해 메인 쿼리의 post_type 을 배열로 확장하면 WP 가 그 배열을 전역 $post_type 에
  복사해 코어 edit.php 가 문자열로 오인하던 문제. 쿼리 직후 `wp` 액션에서 전역만 CPT 슬러그로
  복구(목록 결과는 그대로 유지).

= 0.5.4 =
* "브랜드톡"(edit.php?post_type=brandtalk_review) 목록 하나로 통합 — 별도 "브랜드톡 목록" 서브메뉴 제거.
  이 목록에 브랜드톡 리뷰(CPT) + 별점이 등록된 연동 포스트 타입 글(post 등)이 함께 표시된다.
  컬럼: 종류 / 제목 / 브랜드톡 카테고리 / 별점 / 하위 별점 평균. 제목 클릭 → 해당 항목 편집(리뷰 정보) 화면.
  종류·브랜드톡 카테고리 필터, 별점 정렬. "전체" 카운트는 통합 기준으로 보정, 상태별 서브뷰는 숨김.
  (pre_get_posts + posts_where 로 확장; 첫 서브메뉴 라벨을 "브랜드톡" 으로.)

= 0.5.3 =
* (0.5.4에서 통합됨) 별점 목록 화면 + Frontend::criteria_average() 헬퍼.

= 0.5.2 =
* 하위 별점 항목(Criterion) — 재사용 가능한 평가 항목. `brandtalk_criterion` 택소노미(리뷰 메뉴에서 CRUD),
  브랜드톡 카테고리 편집화면에서 체크박스 연결(텀 메타 brandtalk_criteria), 여러 카테고리에서 공유.
  별점 메타박스에 카테고리별 항목 점수 입력, 프론트/REST 노출. GET /criteria, GET /categories 확장,
  POST /reviews 의 criteria 파라미터, brandtalk/v1/category/criteria_updated 액션.

= 0.5.1 =
* §13.1.3 별점 프론트 노출 — Frontend 컴포넌트: 연동 포스트 타입 + brandtalk_rating 보유 시
  본문/발췌문에 별점 블록 자동 표시. [brandtalk_rating] 숏코드, 관리자 글 목록 별점 컬럼,
  brandtalk/v1/rating_html · rating_position 필터.

= 0.5.0 =
* HivePress 구조 참고 전면 재작성(코어/오토로더/컴포넌트/컨피그/모델/컨트롤러/라우터).
* 기능·정책·슬러그·테이블·REST 네임스페이스는 v0.4.0 과 동일하게 유지.
* REST 응답을 HivePress 봉투({data}/{error})로 통일.
* §11.3 정책값(신고 감점·신뢰 임계·랭킹 상한·지역 잠금)을 설정 화면에 노출.

= 0.4.0 =
* §13.1 플러그인 적용 범위(Post) — 설정 화면, 별점 메타박스, 카테고리 계층형.

= 0.3.0 =
* 최초 릴리스 — 데이터/REST 레이어.
