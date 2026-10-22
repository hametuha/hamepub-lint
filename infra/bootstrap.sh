#!/usr/bin/env bash
#
# hamepub-lint の GCP リソースを用意する（最初に1回だけ。何度実行しても同じ結果になる）。
#
# 作るもの:
#   - API の有効化
#   - Artifact Registry（古いイメージは自動削除）
#   - サービスアカウント 2つ
#       hamepub-lint-run    … Cloud Run の実行用。権限なし
#       hamepub-lint-deploy … GitHub Actions からのデプロイ用
#   - Workload Identity Federation（hametuha/hamepub-lint の master からだけ借用できる）
#   - Cloud Run サービスの器（中身はダミー。初回デプロイで置き換わる）と公開設定
#
# Usage: direnv allow した状態で infra/bootstrap.sh

set -euo pipefail

PROJECT_ID="hamepub"
REGION="asia-northeast1"
SERVICE="hamepub-lint"
AR_REPO="hamepub-lint"
GITHUB_REPO="hametuha/hamepub-lint"
GITHUB_REPO_ID="115276177" # リポジトリ名の乗っ取り対策に ID でも縛る
POOL="github"
PROVIDER="github"
RUN_SA="hamepub-lint-run@${PROJECT_ID}.iam.gserviceaccount.com"
DEPLOY_SA="hamepub-lint-deploy@${PROJECT_ID}.iam.gserviceaccount.com"

# 取り違え防止: direnv の設定と一致しなければ止める
if [ "${CLOUDSDK_CORE_PROJECT:-}" != "${PROJECT_ID}" ]; then
    echo "CLOUDSDK_CORE_PROJECT が ${PROJECT_ID} ではありません。direnv allow してください。" >&2
    exit 1
fi
PROJECT_NUMBER=$(gcloud projects describe "${PROJECT_ID}" --format='value(projectNumber)')

echo "==> API を有効化"
gcloud services enable \
    run.googleapis.com \
    artifactregistry.googleapis.com \
    iam.googleapis.com \
    iamcredentials.googleapis.com \
    sts.googleapis.com

echo "==> Artifact Registry"
if ! gcloud artifacts repositories describe "${AR_REPO}" --location="${REGION}" >/dev/null 2>&1; then
    gcloud artifacts repositories create "${AR_REPO}" \
        --repository-format=docker --location="${REGION}" \
        --description="hamepub-lint container images"
fi
# 最新10件だけ残す
gcloud artifacts repositories set-cleanup-policies "${AR_REPO}" --location="${REGION}" \
    --policy="$(dirname "$0")/cleanup-policy.json" --no-dry-run

echo "==> サービスアカウント"
for sa in hamepub-lint-run:"hamepub-lint Cloud Run runtime" hamepub-lint-deploy:"hamepub-lint GitHub Actions deployer"; do
    name="${sa%%:*}"
    if ! gcloud iam service-accounts describe "${name}@${PROJECT_ID}.iam.gserviceaccount.com" >/dev/null 2>&1; then
        gcloud iam service-accounts create "${name}" --display-name="${sa#*:}"
        CREATED_SA=1
    fi
done
# 作成直後の SA は IAM ポリシーから見えるまで時間がかかる（結果整合性）
if [ -n "${CREATED_SA:-}" ]; then
    echo "    SA の反映を待っています（60秒）..."
    sleep 60
fi

echo "==> デプロイ用 SA の権限"
# Cloud Run の更新（IAM は触れない）
gcloud projects add-iam-policy-binding "${PROJECT_ID}" \
    --member="serviceAccount:${DEPLOY_SA}" --role="roles/run.developer" --condition=None >/dev/null
# イメージの push（このリポジトリだけ）
gcloud artifacts repositories add-iam-policy-binding "${AR_REPO}" --location="${REGION}" \
    --member="serviceAccount:${DEPLOY_SA}" --role="roles/artifactregistry.writer" >/dev/null
# 実行用 SA を Cloud Run に割り当てる権限（実行用 SA だけ）
gcloud iam service-accounts add-iam-policy-binding "${RUN_SA}" \
    --member="serviceAccount:${DEPLOY_SA}" --role="roles/iam.serviceAccountUser" >/dev/null

echo "==> Workload Identity Federation"
if ! gcloud iam workload-identity-pools describe "${POOL}" --location=global >/dev/null 2>&1; then
    gcloud iam workload-identity-pools create "${POOL}" --location=global --display-name="GitHub Actions"
fi
if ! gcloud iam workload-identity-pools providers describe "${PROVIDER}" \
        --workload-identity-pool="${POOL}" --location=global >/dev/null 2>&1; then
    gcloud iam workload-identity-pools providers create-oidc "${PROVIDER}" \
        --workload-identity-pool="${POOL}" --location=global \
        --issuer-uri="https://token.actions.githubusercontent.com" \
        --attribute-mapping="google.subject=assertion.sub,attribute.repository=assertion.repository,attribute.ref=assertion.ref" \
        --attribute-condition="assertion.repository_id == '${GITHUB_REPO_ID}' && assertion.ref == 'refs/heads/master'"
fi
gcloud iam service-accounts add-iam-policy-binding "${DEPLOY_SA}" \
    --role="roles/iam.workloadIdentityUser" \
    --member="principalSet://iam.googleapis.com/projects/${PROJECT_NUMBER}/locations/global/workloadIdentityPools/${POOL}/attribute.repository/${GITHUB_REPO}" >/dev/null

echo "==> Cloud Run サービス（初回のみダミーで作成）と公開設定"
if ! gcloud run services describe "${SERVICE}" --region="${REGION}" >/dev/null 2>&1; then
    gcloud run deploy "${SERVICE}" --region="${REGION}" \
        --image=us-docker.pkg.dev/cloudrun/container/hello \
        --service-account="${RUN_SA}" --max-instances=1 --no-allow-unauthenticated
fi
# 公開サービスなので誰でも呼べるようにする（デプロイ用 SA は IAM を変更できないため、ここでやる）
gcloud run services add-iam-policy-binding "${SERVICE}" --region="${REGION}" \
    --member=allUsers --role=roles/run.invoker >/dev/null

cat <<MSG

完了しました。GitHub Actions の Variables に以下を設定してください:
  GCP_WORKLOAD_IDENTITY_PROVIDER = projects/${PROJECT_NUMBER}/locations/global/workloadIdentityPools/${POOL}/providers/${PROVIDER}
  GCP_SERVICE_ACCOUNT            = ${DEPLOY_SA}
MSG
