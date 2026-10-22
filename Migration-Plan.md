# hamepub-lint Cloud Run 移行計画

_2026-10-22 改訂（2025-05-21 版を破棄して仕切り直し）_ / Issue: #1

## 1. 背景

旧 EC2（`i-0f59accd4cc4c5b95` / EIP `13.113.144.67`）は Amazon Linux 2018.03 + PHP 7.1.33 のまま放置されており、2026-10-21 に停止した。
停止に伴い、以下が現在使えない。

| 利用者 | 呼び出し方 |
|---|---|
| はめにゅー本体 | `hametuha/hametuha` の `CompiledFiles::validate()` が `POST https://lint.hametuha.pub/validator`（バージョン指定なし） |
| bitfield/checkepub | 外部の Go 製 CLI。`POST http://lint.hametuha.pub/validator` |
| Web UI | `https://lint.hametuha.pub/` |

## 2. 方針

- **API は互換を保つ**：`POST /validator`、`POST /validator/{version}`、base64 の body、JSON レスポンスの形は変えない。利用者側の改修は不要にする。
- **コンテナ1つで完結させる**：PHP + JRE + EPUBCheck jar を1イメージに焼き込む。GCS・Cloud Functions・Scheduler は使わない。
- **デフォルトを EPUBCheck 5.x に上げる**：4.0.2（2016年）は EPUB 3.3 に未対応。判定が厳しくなるが、書店基準に合わせるべき。
- **CI/CD は GitHub Actions**：Workload Identity Federation で GCP に認証し、鍵は持たない。
- **DNS は Route53 のまま**：Cloud DNS に移してもドメイン登録が AWS に残り、管理場所の数は減らないため。

旧計画（Quarkus 書き直し、jar の自動取得基盤、Cloud Build）は、EPUBCheck の更新頻度（年1回程度）に対して過剰なので採らない。

## 3. 構成

```
Client ──HTTPS──▶ lint.hametuha.pub (Route53 CNAME → ghs.googlehosted.com)
                        │
                        ▼
                Cloud Run: hamepub-lint (asia-northeast1, project: hamepub)
                  ├ PHP 8.5 (Slim 4)  … HTTP を受けて jar を起動
                  ├ Temurin JRE 21
                  └ /opt/epubcheck/{5.4.0, 4.0.2}/epubcheck.jar
```

| 項目 | 値 |
|---|---|
| GCP プロジェクト | `hamepub`（番号 1005215085682） |
| リージョン | `asia-northeast1` |
| メモリ / CPU | 1Gi / 1 |
| concurrency | 4（JVM を同時に起動しすぎない） |
| min / max instances | 0 / 3（濫用時の課金上限） |
| リクエスト上限 | Cloud Run の 32MB + アプリ側でもサイズを検査 |
| 公開 | 未認証アクセスを許可（公開サービスのため） |
| イメージ置き場 | Artifact Registry `asia-northeast1-docker.pkg.dev/hamepub/hamepub-lint` |

### EPUBCheck のバージョン

- 同梱：**5.4.0**（デフォルト）と **4.0.2**（従来の挙動が必要な人向け）。3.x は廃止。
- jar は Docker のビルド時に GitHub Releases から取得する（リポジトリには置かない）。
- 新しいリリースは、週1回の GitHub Actions が検知して PR を出す。

### 廃止するもの

- `/payload`（GitHub Webhook で `git pull` する仕組み。署名検証が無効化されていた）
- `bin/deploy.sh`、`bin/installer.sh`
- `app/Hametuha/PubLint/S3.php`（空のスタブ）

## 4. 管理場所（忘れないために）

| もの | 場所 |
|---|---|
| ドメイン登録 `hametuha.pub` | AWS Route53 Domains（アカウント 561938084780）。自動更新 ON、期限 2026-12-25 |
| DNS ゾーン | AWS Route53（`Z3INJQTM7GDWHO`）。手作業で管理 |
| サービス・イメージ | GCP `hamepub` |
| デプロイ | このリポジトリの GitHub Actions |

README にも同じ内容を書く。

## 5. 作業ステップ

### Phase 1: アプリのコンテナ化（ローカル）
- [x] PHP 8.5 へ移行（Slim 3 → Slim 4、PHPUnit 更新）。依存に問題があれば 8.4 に下げる
- [x] jar の場所を環境変数で切り替えられるようにする（`EPUBCHECK_DIR`）
- [x] デフォルトのバージョンを 5.4.0 に変更
- [x] `/payload` などの不要物を削除
- [x] Dockerfile と docker-compose.yml を作り直す
- [x] 既存テストを通し、サンプル EPUB で 5.4.0 と 4.0.2 の検証が通るテストを追加

### Phase 2: GCP 準備
- [ ] API 有効化：Cloud Run、Artifact Registry、IAM Credentials
- [ ] Artifact Registry リポジトリ作成
- [ ] デプロイ用サービスアカウントと Workload Identity Federation（`hametuha/hamepub-lint` のみ許可）
- [ ] 実行用サービスアカウント（権限なし）

### Phase 3: CI/CD
- [ ] GitHub Actions：push / PR でテスト
- [ ] GitHub Actions：`master` への push でビルドしてデプロイ
- [ ] GitHub Actions：EPUBCheck の新リリース検知

### Phase 4: 切り替え
- [ ] `*.run.app` の URL で Web UI・API の動作確認
- [ ] Cloud Run ドメインマッピング `lint.hametuha.pub` を作成
- [ ] Route53：`lint` を A → `CNAME ghs.googlehosted.com.` に変更
- [ ] 証明書の発行を確認し、はめにゅー本体から検証が通ることを確認

### Phase 5: 後片付け
- [ ] Route53 の不要レコード削除：apex A、`www`、MX、xserver 向け SPF、`_adsp._domainkey` ×2
- [ ] Route53 に `v=spf1 -all` と Null MX（`0 .`）を追加
- [ ] EC2・EBS・EIP を削除（hametuha-infra#32 にも報告）
- [ ] README 更新

## 6. 別 Issue に切り出すもの

- XServer の契約が残っていれば解約
- bitfield/checkepub への連絡（`http://` → `https://`、デフォルトが 5.x に変わったこと）
