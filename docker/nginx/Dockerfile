# Web server image: the unprivileged nginx stable branch plus the Alpine
# security fixes released after the base image was built. The site config is a
# template rendered at start-up (default.conf.template, mounted by compose).
FROM nginxinc/nginx-unprivileged:1.30-alpine
USER root
RUN apk upgrade --no-cache
USER 101
