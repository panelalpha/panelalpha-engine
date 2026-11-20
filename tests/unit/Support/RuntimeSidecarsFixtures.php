<?php

namespace Tests\Support;

trait RuntimeSidecarsFixtures
{
    private const STACK = <<<'YAML'
    services:
      app:
        build: .
        ports:
          - "8080:8080"
        depends_on:
          - db
          - mailpit
        environment:
          DATABASE_URL: postgres://shop:secret@db:5432/shop
      db:
        image: postgres:16
        ports:
          - "5432:5432"
        environment:
          POSTGRES_USER: shop
          POSTGRES_PASSWORD: secret
          POSTGRES_DB: shop
        volumes:
          - dbdata:/var/lib/postgresql/data
      mailpit:
        image: axllent/mailpit
        ports:
          - "8025:8025"
    volumes:
      dbdata:
    YAML;
}
