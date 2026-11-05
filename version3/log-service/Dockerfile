FROM python:3.12-alpine
ENV PYTHONDONTWRITEBYTECODE=1 PYTHONUNBUFFERED=1
WORKDIR /app
COPY log-service/requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt && addgroup -S app && adduser -S app -G app
COPY log-service/app.py .
USER app
EXPOSE 8080
CMD ["uvicorn", "app:app", "--host", "0.0.0.0", "--port", "8080"]
