## 1.4.0 (11-11-2026)

- Support scoped Heroku Control credentials for queue worker autoscaling

## 1.3.0 (03-11-2026)

- Keep queue jobs moving during Heroku API delays with bounded requests and retries on subsequent queue events

## 1.2.2 (23-10-2026)

- Require PCNTL so Laravel workers can handle stop signals and enforce job timeouts on Heroku

## 1.2.1 (15-07-2026)

- Gracefully stop an idle queue worker before scaling its Heroku formation to zero, preventing it from claiming another job during shutdown
