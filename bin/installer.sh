#!/usr/bin/env bash

echo "Installing epubhcheck $1..."

cd jar

if [ -e "epubcheck-$1" ]; then
    echo 'Version already exists.'
    exit 1
fi

wget https://github.com/IDPF/epubcheck/releases/download/v$1/epubcheck-$1.zip
unzip epubcheck-$1.zip
rm epubcheck-$1.zip
