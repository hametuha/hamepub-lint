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

## ePubCheck の更新

毎週月曜に GitHub Actions が新しいリリースを確認し、あればデフォルトのバージョンを差し替える PR を作る（テストは workflow の中で実行済み）。
手動で更新するなら `Dockerfile` の `EPUBCHECK_VERSIONS` を書き換える。
