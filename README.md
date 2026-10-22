# hamepub-lint

ePubCheck を REST API で実行するサービス。https://lint.hametuha.pub/

```
POST /validator            # 最新の ePubCheck で検証
POST /validator/{version}  # バージョン指定（例: 4.0.2）
body: BASE64 エンコードした ePub
```

レスポンスの `success` が `true` なら有効な ePub。

## 構成

PHP 8.5（Slim 4）+ Temurin JRE 21 + ePubCheck を1つのコンテナにまとめ、Cloud Run で動かしている。
同梱する ePubCheck のバージョンは `Dockerfile` の `EPUBCHECK_VERSIONS` で決まり、最も新しいものがデフォルトになる。

## どこに何があるか

| もの | 場所 |
|---|---|
| サービス | GCP プロジェクト `hamepub` / Cloud Run `hamepub-lint`（asia-northeast1） |
| コンテナイメージ | Artifact Registry `asia-northeast1-docker.pkg.dev/hamepub/hamepub-lint` |
| デプロイ | GitHub Actions（`master` に push すると自動デプロイ） |
| ドメイン登録 `hametuha.pub` | **AWS** Route53 Domains（アカウント 561938084780、自動更新） |
| DNS | **AWS** Route53。`lint` は `ghs.googlehosted.com.` への CNAME |
| ドメインの所有権確認 | Google Search Console（apex の `google-site-verification` TXT。消さないこと） |

ドメインと DNS だけ AWS にある点に注意。

## 開発

```bash
docker compose build
docker compose run --rm app composer install
docker compose run --rm app vendor/bin/phpunit   # 実際に ePubCheck を動かすテスト
docker compose up                                 # http://localhost:8080
```

gcloud / aws の向き先は `.envrc`（direnv）で固定している。最初に `direnv allow` すること。

## インフラ

- `infra/bootstrap.sh`：GCP の初期構築（API、Artifact Registry、サービスアカウント、Workload Identity Federation、公開設定）。何度実行しても同じ結果になる
- `infra/service.yaml`：Cloud Run の設定（CPU、メモリ、同時実行数など）。変更したら `master` に push すれば反映される

## 自動更新

放っておいても依存が更新されていく。壊れたときだけ Issue（@fumikito へのメンション）で知らせが来る。

| 対象 | 仕組み |
|---|---|
| PHP・Java・Debian・Composer のイメージ | Dependabot（毎週月曜）。`Dockerfile` はダイジェストで固定しているので、上流のセキュリティ修正のたびに PR が来る |
| Composer のパッケージ | Dependabot（毎週月曜） |
| GitHub Actions | Dependabot（毎週月曜） |
| ePubCheck | `epubcheck-update.yml`（毎週月曜）が新リリースを確認して PR を作る |

1. PR は必須チェック（`phpunit`）が通ると自動でマージされる
2. 自動マージでは deploy が起動しないため、`deploy.yml` の定期実行（毎日 03:00 JST）が拾って本番に反映する。本番が `master` と同じなら何もしない
3. Test（自動 PR のもの）・Deploy・ePubCheck update が失敗すると、`notify-failure.yml` が Issue「自動更新が失敗しました」を作る（開いていればコメントを追加）

本番に入っているコミットは、リポジトリのトップの Deployments（`production`）で確認できる。

### 手で直す必要があるもの

- `Dockerfile` の `debian:trixie-slim`：Debian のコードネームは Dependabot が上げないので、次の Debian が出たら書き換える（ePubCheck のダウンロードにしか使っていないので急がない）
- `composer.json` の `"php": "^8.5"`：PHP 9 が出たら見直す

## ePubCheck の更新

毎週月曜に GitHub Actions が新しいリリースを確認し、あればデフォルトのバージョンを差し替える PR を作る（テストは workflow の中で実行済み）。
手動で更新するなら `Dockerfile` の `EPUBCHECK_VERSIONS` を書き換える。
