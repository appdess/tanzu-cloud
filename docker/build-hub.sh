#!/bin/bash
# quick and dirty build script for tito-app ;-)
# https://github.com/appdess/Tanzu-VMC-Demo

VERSION=v1
set -euo pipefail
cd /home/tkg/Downloads/tanzu-cloud/docker/frontend
docker build -t frontend:$VERSION .
docker tag frontend:$VERSION adess/vmc-demo-k8s:$VERSION
docker push adess/vmc-demo-k8s:$VERSION

# build sql container
cd /home/tkg/Downloads/tanzu-cloud/docker/sql
docker build -t sql:$VERSION .
docker tag sql:$VERSION adess/tito-db:$VERSION
docker push adess/tito-db:$VERSION