from app.dependencies import embedding_model

def generate_embedding(text: str):
    return embedding_model.encode(text).tolist()
