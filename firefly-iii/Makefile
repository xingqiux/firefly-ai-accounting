PHP ?= php
HOST ?= 127.0.0.1
PORT ?= 8001

.PHONY: up down restart dev

up:
	$(PHP) artisan serve --host=$(HOST) --port=$(PORT)

down:
	@PID=$$(lsof -tiTCP:$(PORT) -sTCP:LISTEN); \
	if [ -n "$$PID" ]; then \
		kill $$PID; \
		echo "Stopped server on $(HOST):$(PORT)"; \
	else \
		echo "No server listening on $(HOST):$(PORT)"; \
	fi

restart: down up

dev: up
